<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
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
        Log::info('PayPal: Creating order', [
            'reservation_id' => $reservation->id,
            'amount' => $payment->format(),
            'currency' => config('resrv-config.currency_isoCode'),
            'entry_title' => $reservation->entry()->title,
        ]);

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

        $response = $ordersController->createOrder(['body' => $orderRequest]);
        $result = $response->getResult();

        $approvalUrl = collect($result->getLinks())
            ->firstWhere(fn ($link) => $link->getRel() === 'payer-action')
            ?->getHref();

        Log::info('PayPal: Order created successfully', [
            'reservation_id' => $reservation->id,
            'order_id' => $result->getId(),
            'approval_url' => $approvalUrl,
        ]);

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $result->getId();
        $paymentIntent->client_secret = '';
        $paymentIntent->redirectTo = $approvalUrl;

        return $paymentIntent;
    }

    public function refund(Reservation $reservation)
    {
        Log::info('PayPal: Initiating refund', [
            'reservation_id' => $reservation->id,
            'capture_id' => $reservation->payment_id,
            'amount' => $reservation->payment->format(),
            'currency' => config('resrv-config.currency_isoCode'),
        ]);

        $paymentsController = $this->client->getPaymentsController();

        try {
            $response = $paymentsController->refundCapturedPayment([
                'captureId' => $reservation->payment_id,
                'body' => RefundRequestBuilder::init()
                    ->amount(
                        MoneyBuilder::init(
                            config('resrv-config.currency_isoCode'),
                            $reservation->payment->format()
                        )->build()
                    )
                    ->build(),
            ]);

            $result = $response->getResult();

            Log::info('PayPal: Refund completed successfully', [
                'reservation_id' => $reservation->id,
                'capture_id' => $reservation->payment_id,
                'refund_id' => is_array($result) ? ($result['id'] ?? null) : $result->getId(),
            ]);

            return $result;
        } catch (\Exception $exception) {
            Log::error('PayPal: Refund failed', [
                'reservation_id' => $reservation->id,
                'capture_id' => $reservation->payment_id,
                'error' => $exception->getMessage(),
            ]);

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

        Log::info('PayPal: Handling redirect back', [
            'reservation_id' => $id,
            'token' => $token,
            'cancelled' => $cancelled ? 'yes' : 'no',
            'ip' => request()->ip(),
        ]);

        $reservation = Reservation::findOrFail($id);

        if ($cancelled) {
            Log::info('PayPal: User cancelled payment', [
                'reservation_id' => $reservation->id,
            ]);

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
                Log::warning('PayPal: Capture rate limit exceeded', [
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
            Log::info('PayPal: Retrieving order for validation', [
                'reservation_id' => $reservation->id,
                'token' => $token,
            ]);

            try {
                $orderResponse = $ordersController->getOrder(['id' => $token]);
                $order = $orderResponse->getResult();

                Log::info('PayPal: Order retrieved successfully', [
                    'reservation_id' => $reservation->id,
                    'token' => $token,
                    'order_status' => is_array($order) ? ($order['status'] ?? null) : $order->getStatus(),
                ]);
            } catch (\Exception $e) {
                // Don't penalize for PayPal API failures - not a security event
                Log::error('PayPal: Order retrieval failed', [
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
                Log::error('PayPal: Order missing purchase units', [
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

                Log::warning('PayPal: Order reference_id mismatch - payment NOT captured', [
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
            Log::info('PayPal: Capturing order', [
                'reservation_id' => $reservation->id,
                'token' => $token,
            ]);

            try {
                $response = $ordersController->captureOrder(['id' => $token]);
                $result = $response->getResult();

                Log::info('PayPal: Capture response received', [
                    'reservation_id' => $reservation->id,
                    'token' => $token,
                    'result_type' => is_array($result) ? 'array' : get_class($result),
                ]);
            } catch (\Exception $e) {
                // Don't penalize for PayPal API failures - not a security event
                Log::error('PayPal: Capture failed', [
                    'token' => $token,
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => false,
                    'reservation' => $reservation->toArray(),
                ];
            }

            // Handle both object and array responses from PayPal SDK
            $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

            Log::info('PayPal: Capture status', [
                'reservation_id' => $reservation->id,
                'token' => $token,
                'status' => $status,
            ]);

            if ($status === 'COMPLETED') {
                // Success - clear the rate limit for this IP
                $rateLimiter->clear($rateLimitKey);

                $resultPurchaseUnits = is_array($result)
                    ? ($result['purchase_units'] ?? [])
                    : $result->getPurchaseUnits();

                $payments = is_array($resultPurchaseUnits[0] ?? null)
                    ? ($resultPurchaseUnits[0]['payments'] ?? null)
                    : ($resultPurchaseUnits[0]?->getPayments());

                $captures = is_array($payments)
                    ? ($payments['captures'] ?? [])
                    : ($payments?->getCaptures());

                if (empty($resultPurchaseUnits) || empty($captures)) {
                    Log::error('PayPal: Capture response missing expected data', [
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

                $captureId = is_array($captures[0] ?? null)
                    ? ($captures[0]['id'] ?? null)
                    : $captures[0]->getId();

                $reservation->update(['payment_id' => $captureId]);

                Log::info('PayPal: Payment captured successfully', [
                    'reservation_id' => $reservation->id,
                    'capture_id' => $captureId,
                    'token' => $token,
                ]);

                return [
                    'status' => true,
                    'reservation' => $reservation->fresh()->toArray(),
                ];
            }

            if ($status === 'PENDING') {
                Log::info('PayPal: Payment pending', [
                    'reservation_id' => $reservation->id,
                    'token' => $token,
                ]);

                return [
                    'status' => 'pending',
                    'reservation' => $reservation->toArray(),
                ];
            }

            Log::warning('PayPal: Unexpected capture status', [
                'reservation_id' => $reservation->id,
                'token' => $token,
                'status' => $status,
            ]);
        }

        Log::warning('PayPal: Redirect back without token', [
            'reservation_id' => $reservation->id,
        ]);

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

        Log::info('PayPal: Webhook received', [
            'event_type' => $data['event_type'] ?? 'unknown',
            'event_id' => $data['id'] ?? null,
            'resource_type' => $data['resource_type'] ?? null,
        ]);

        if (! $data) {
            Log::warning('PayPal: Webhook invalid JSON payload');
            abort(403);
        }

        // SECURITY: Verify webhook signature FIRST before any database operations
        // This prevents enumeration attacks and ensures we only process authentic PayPal webhooks
        Log::info('PayPal: Verifying webhook signature');

        try {
            $isValid = $this->webhookVerifier->verify($request, $payload);

            if (! $isValid) {
                Log::warning('PayPal: Webhook signature invalid');
                abort(403);
            }

            Log::info('PayPal: Webhook signature verified successfully');
        } catch (\Exception $e) {
            Log::error('PayPal: Webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            abort(403);
        }

        // Only proceed with processing after signature is verified
        $eventType = $data['event_type'] ?? null;

        if (! in_array($eventType, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED'])) {
            Log::info('PayPal: Webhook event type not handled', [
                'event_type' => $eventType,
            ]);

            return response()->json([], 200);
        }

        $captureId = $data['resource']['id'] ?? null;

        if (! $captureId) {
            Log::warning('PayPal: Webhook missing capture ID', [
                'event_type' => $eventType,
            ]);

            return response()->json([], 200);
        }

        $reservation = Reservation::findByPaymentId($captureId)->first();

        if (! $reservation) {
            Log::info('PayPal: Webhook reservation not found for capture ID', [
                'capture_id' => $captureId,
                'event_type' => $eventType,
            ]);

            return response()->json([], 200);
        }

        Log::info('PayPal: Webhook processing', [
            'event_type' => $eventType,
            'capture_id' => $captureId,
            'reservation_id' => $reservation->id,
            'reservation_status' => $reservation->status->value ?? $reservation->status,
        ]);

        if ($reservation->status === ReservationStatus::CONFIRMED) {
            Log::info('PayPal: Webhook skipped - reservation already confirmed', [
                'reservation_id' => $reservation->id,
                'capture_id' => $captureId,
            ]);

            return response()->json([], 200);
        }

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            Log::info('PayPal: Dispatching ReservationConfirmed event', [
                'reservation_id' => $reservation->id,
                'capture_id' => $captureId,
            ]);
            ReservationConfirmed::dispatch($reservation);
        } else {
            Log::info('PayPal: Dispatching ReservationCancelled event', [
                'reservation_id' => $reservation->id,
                'capture_id' => $captureId,
                'event_type' => $eventType,
            ]);
            ReservationCancelled::dispatch($reservation);
        }

        return response()->json([], 200);
    }

    public function verifyWebhook()
    {
        return true;
    }
}
