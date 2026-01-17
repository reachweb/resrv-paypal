<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PaymentSourceBuilder;
use PaypalServerSdkLib\Models\Builders\PayPalWalletBuilder;
use PaypalServerSdkLib\Models\Builders\PayPalWalletExperienceContextBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\PayPalExperienceUserAction;
use PaypalServerSdkLib\PaypalServerSdkClient;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationCancelled;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Livewire\Traits\HandlesStatamicQueries;
use Reach\StatamicResrv\Models\Reservation;

class PaypalPaymentGateway implements PaymentInterface
{
    use HandlesStatamicQueries;
    use PaypalApiTrait;

    protected PaypalServerSdkClient $client;

    protected WebhookSignatureVerifier $webhookVerifier;

    public function __construct(?WebhookSignatureVerifier $webhookVerifier = null)
    {
        $this->client = app(PaypalServerSdkClient::class);
        $this->webhookVerifier = $webhookVerifier ?? app(WebhookSignatureVerifier::class);
    }

    public function paymentIntent($payment, Reservation $reservation, $data)
    {
        $ordersController = $this->client->getOrdersController();

        $orderRequest = OrderRequestBuilder::init(CheckoutPaymentIntent::CAPTURE, [
            PurchaseUnitRequestBuilder::init(
                AmountWithBreakdownBuilder::init(
                    config('resrv-config.currency_isoCode'),
                    $payment->format()
                )->build()
            )
                ->referenceId((string) $reservation->id)
                ->description($reservation->entry()->title)
                ->build(),
        ])
            ->paymentSource(
                PaymentSourceBuilder::init()
                    ->paypal(
                        PayPalWalletBuilder::init()
                            ->experienceContext(
                                PayPalWalletExperienceContextBuilder::init()
                                    ->returnUrl($this->getCheckoutCompleteEntry()->absoluteUrl().'?id='.$reservation->id)
                                    ->cancelUrl($this->getCheckoutCompleteEntry()->absoluteUrl().'?id='.$reservation->id.'&cancelled=true')
                                    ->brandName(config('app.name'))
                                    ->userAction(PayPalExperienceUserAction::PAY_NOW)
                                    ->build()
                            )
                            ->build()
                    )
                    ->build()
            )
            ->build();

        $response = $ordersController->ordersCreate(['body' => $orderRequest]);
        $result = $response->getResult();

        $approvalUrl = collect($result->getLinks())
            ->firstWhere(fn ($link) => $link->getRel() === 'payer-action')
            ?->getHref();

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $result->getId();
        $paymentIntent->client_secret = '';
        $paymentIntent->redirectTo = $approvalUrl;

        return $paymentIntent;
    }

    public function refund(Reservation $reservation)
    {
        $paymentsController = $this->client->getPaymentsController();

        try {
            $response = $paymentsController->capturesRefund([
                'capture_id' => $reservation->payment_id,
                'body' => RefundRequestBuilder::init()
                    ->amount(
                        AmountWithBreakdownBuilder::init(
                            config('resrv-config.currency_isoCode'),
                            $reservation->payment->format()
                        )->build()
                    )
                    ->build(),
            ]);

            return $response->getResult();
        } catch (\Exception $exception) {
            throw new RefundFailedException($exception->getMessage());
        }
    }

    public function getPublicKey(Reservation $reservation)
    {
        return config('services.paypal.client_id');
    }

    public function getSecretKey(Reservation $reservation)
    {
        return config('services.paypal.client_secret');
    }

    public function getWebhookSecret(Reservation $reservation)
    {
        return config('services.paypal.webhook_id');
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function redirectsForPayment(): bool
    {
        return true;
    }

    public function handleRedirectBack(): array
    {
        $id = request()->input('id');
        $token = request()->input('token');
        $cancelled = request()->input('cancelled');

        $reservation = Reservation::findOrFail($id);

        if ($cancelled) {
            return [
                'status' => false,
                'reservation' => $reservation->toArray(),
            ];
        }

        if ($token) {
            // SECURITY: Rate limit suspicious attempts per IP
            // Failed validation attempts are penalized more heavily to prevent enumeration
            $rateLimiter = app(RateLimiter::class);
            $rateLimitKey = 'paypal-capture:'.request()->ip();
            $maxAttempts = 10;

            if ($rateLimiter->tooManyAttempts($rateLimitKey, $maxAttempts)) {
                Log::warning('PayPal capture rate limit exceeded', [
                    'ip' => request()->ip(),
                    'reservation_id' => $reservation->id,
                ]);

                return [
                    'status' => false,
                    'reservation' => $reservation->toArray(),
                ];
            }

            $ordersController = $this->client->getOrdersController();

            // SECURITY: Get order details FIRST to validate reference_id BEFORE capturing payment
            // This ensures we don't capture payment for a mismatched reservation
            try {
                $orderResponse = $ordersController->ordersGet(['id' => $token]);
                $order = $orderResponse->getResult();
            } catch (\Exception $e) {
                // Don't penalize for PayPal API failures - not a security event
                Log::error('PayPal order retrieval failed', [
                    'token' => $token,
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => false,
                    'reservation' => $reservation->toArray(),
                ];
            }

            $purchaseUnits = $order->getPurchaseUnits();
            if (empty($purchaseUnits)) {
                Log::error('PayPal order missing purchase units', [
                    'token' => $token,
                    'reservation_id' => $reservation->id,
                ]);

                return [
                    'status' => false,
                    'reservation' => $reservation->toArray(),
                ];
            }

            $referenceId = $purchaseUnits[0]->getReferenceId();
            if ($referenceId !== (string) $reservation->id) {
                // SECURITY: This is a potential attack - penalize heavily (3 strikes)
                $rateLimiter->hit($rateLimitKey, 300); // 5 minute decay
                $rateLimiter->hit($rateLimitKey, 300);
                $rateLimiter->hit($rateLimitKey, 300);

                Log::warning('PayPal order reference_id mismatch - payment NOT captured', [
                    'reservation_id' => $reservation->id,
                    'order_reference_id' => $referenceId,
                    'ip' => request()->ip(),
                ]);

                return [
                    'status' => false,
                    'reservation' => $reservation->toArray(),
                ];
            }

            // Validation passed - count as normal attempt
            $rateLimiter->hit($rateLimitKey, 60);

            // Now safe to capture - order is validated
            try {
                $response = $ordersController->ordersCapture(['id' => $token]);
                $result = $response->getResult();
            } catch (\Exception $e) {
                // Don't penalize for PayPal API failures - not a security event
                Log::error('PayPal capture failed', [
                    'token' => $token,
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => false,
                    'reservation' => $reservation->toArray(),
                ];
            }

            if ($result->getStatus() === 'COMPLETED') {
                // Success - clear the rate limit for this IP
                $rateLimiter->clear($rateLimitKey);

                $resultPurchaseUnits = $result->getPurchaseUnits();
                $payments = $resultPurchaseUnits[0]?->getPayments();
                $captures = $payments?->getCaptures();

                if (empty($resultPurchaseUnits) || empty($captures)) {
                    Log::error('PayPal capture response missing expected data', [
                        'token' => $token,
                        'reservation_id' => $reservation->id,
                        'has_purchase_units' => ! empty($resultPurchaseUnits),
                        'has_captures' => ! empty($captures),
                    ]);

                    return [
                        'status' => false,
                        'reservation' => $reservation->toArray(),
                    ];
                }

                $captureId = $captures[0]->getId();

                $reservation->update(['payment_id' => $captureId]);

                return [
                    'status' => true,
                    'reservation' => $reservation->fresh()->toArray(),
                ];
            }

            if ($result->getStatus() === 'PENDING') {
                return [
                    'status' => 'pending',
                    'reservation' => $reservation->toArray(),
                ];
            }
        }

        return [
            'status' => false,
            'reservation' => $reservation->toArray(),
        ];
    }

    public function handlePaymentPending(): bool|array
    {
        return false;
    }

    public function verifyPayment($request)
    {
        $payload = $request->getContent();
        $data = json_decode($payload, true);

        if (! $data) {
            Log::warning('PayPal webhook: Invalid JSON payload');
            abort(403);
        }

        // SECURITY: Verify webhook signature FIRST before any database operations
        // This prevents enumeration attacks and ensures we only process authentic PayPal webhooks
        try {
            $isValid = $this->webhookVerifier->verify($request, $payload);

            if (! $isValid) {
                Log::warning('PayPal webhook: Invalid signature');
                abort(403);
            }
        } catch (\Exception $e) {
            Log::error('PayPal webhook signature verification failed: '.$e->getMessage());
            abort(403);
        }

        // Only proceed with processing after signature is verified
        $eventType = $data['event_type'] ?? null;

        if (! in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED'])) {
            return response()->json([], 200);
        }

        $captureId = $data['resource']['id'] ?? null;

        if (! $captureId) {
            Log::warning('PayPal webhook: Missing capture ID');

            return response()->json([], 200);
        }

        $reservation = Reservation::findByPaymentId($captureId)->first();

        if (! $reservation) {
            Log::info('PayPal: Reservation not found for capture id '.$captureId);

            return response()->json([], 200);
        }

        if ($reservation->status === ReservationStatus::CONFIRMED) {
            return response()->json([], 200);
        }

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            ReservationConfirmed::dispatch($reservation);
        } else {
            ReservationCancelled::dispatch($reservation);
        }

        return response()->json([], 200);
    }

    public function verifyWebhook()
    {
        return true;
    }
}
