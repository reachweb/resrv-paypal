<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Support\Facades\Http;
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

    protected PaypalServerSdkClient $client;

    public function __construct()
    {
        $this->client = app(PaypalServerSdkClient::class);
    }

    protected function getPaypalApiBaseUrl(): string
    {
        return config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    protected function getAccessToken(): string
    {
        $response = Http::withBasicAuth(
            config('services.paypal.client_id'),
            config('services.paypal.client_secret')
        )->asForm()->post($this->getPaypalApiBaseUrl().'/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to obtain PayPal access token');
        }

        return $response->json('access_token');
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

        // SECURITY: Store the pending order ID to validate on capture
        // This prevents token substitution attacks where an attacker uses
        // a valid order token from one reservation against another
        $reservation->update(['pending_order_id' => $result->getId()]);

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
            // SECURITY: Validate that the order token matches the one we created for this reservation
            // This prevents token substitution attacks (IDOR)
            if ($reservation->pending_order_id && $reservation->pending_order_id !== $token) {
                Log::warning('PayPal order token mismatch', [
                    'reservation_id' => $reservation->id,
                    'expected_token' => $reservation->pending_order_id,
                    'received_token' => $token,
                ]);

                return [
                    'status' => false,
                    'reservation' => $reservation->toArray(),
                ];
            }

            try {
                $ordersController = $this->client->getOrdersController();
                $response = $ordersController->ordersCapture(['id' => $token]);
                $result = $response->getResult();

                // SECURITY: Validate that the order's reference_id matches our reservation ID
                // This is a defense-in-depth check to ensure order-reservation binding
                $referenceId = $result->getPurchaseUnits()[0]->getReferenceId();
                if ($referenceId !== (string) $reservation->id) {
                    Log::warning('PayPal order reference_id mismatch', [
                        'reservation_id' => $reservation->id,
                        'order_reference_id' => $referenceId,
                    ]);

                    return [
                        'status' => false,
                        'reservation' => $reservation->toArray(),
                    ];
                }

                if ($result->getStatus() === 'COMPLETED') {
                    $captureId = $result->getPurchaseUnits()[0]
                        ->getPayments()
                        ->getCaptures()[0]
                        ->getId();

                    // Clear pending_order_id and store the capture ID
                    $reservation->update([
                        'payment_id' => $captureId,
                        'pending_order_id' => null,
                    ]);

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
            } catch (\Exception $e) {
                Log::error('PayPal capture failed: '.$e->getMessage());
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
            $isValid = $this->verifyWebhookSignature($request, $payload);

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

    protected function verifyWebhookSignature($request, string $payload): bool
    {
        $webhookId = config('services.paypal.webhook_id');

        if (! $webhookId) {
            throw new \RuntimeException('PayPal webhook ID is not configured. Set PAYPAL_WEBHOOK_ID in your .env file.');
        }

        $headers = [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
        ];

        foreach ($headers as $key => $value) {
            if (empty($value)) {
                Log::warning("PayPal webhook missing header: {$key}");

                return false;
            }
        }

        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->post($this->getPaypalApiBaseUrl().'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $headers['auth_algo'],
                'cert_url' => $headers['cert_url'],
                'transmission_id' => $headers['transmission_id'],
                'transmission_sig' => $headers['transmission_sig'],
                'transmission_time' => $headers['transmission_time'],
                'webhook_id' => $webhookId,
                'webhook_event' => json_decode($payload, true),
            ]);

        if (! $response->successful()) {
            Log::error('PayPal webhook verification API error: '.$response->body());

            return false;
        }

        $verificationStatus = $response->json('verification_status');

        return $verificationStatus === 'SUCCESS';
    }
}
