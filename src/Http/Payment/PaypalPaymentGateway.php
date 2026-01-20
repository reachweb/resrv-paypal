<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
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
        Log::info('PayPal: Creating order for JS SDK', [
            'reservation_id' => $reservation->id,
            'amount' => $payment->format(),
            'currency' => config('resrv-config.currency_isoCode'),
            'entry_title' => $reservation->entry()->title,
        ]);

        $ordersController = $this->client->getOrdersController();

        // Create order without payment source - JS SDK handles payment method selection
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
        ])->build();

        $response = $ordersController->createOrder(['body' => $orderRequest]);
        $result = $response->getResult();

        Log::info('PayPal: Order created successfully for JS SDK', [
            'reservation_id' => $reservation->id,
            'order_id' => $result->getId(),
        ]);

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $result->getId();
        // For JS SDK, we pass the order ID as client_secret (used by frontend)
        $paymentIntent->client_secret = $result->getId();

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
        return false;
    }

    public function handleRedirectBack(): array
    {
        // With JS SDK flow, the user is redirected here after successful capture
        // The capture was already done by the JS SDK calling our capture endpoint
        // We just need to verify the reservation has a valid payment_id (capture ID)

        $id = request()->input('id');
        $cancelled = request()->input('cancelled');

        Log::info('PayPal: Handling redirect back (JS SDK flow)', [
            'reservation_id' => $id,
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

        // Check if payment was captured (payment_id should be set by capture endpoint)
        if ($reservation->payment_id) {
            Log::info('PayPal: Payment verified - capture ID found', [
                'reservation_id' => $reservation->id,
                'capture_id' => $reservation->payment_id,
            ]);

            return [
                'status' => true,
                'reservation' => $reservation->toArray(),
            ];
        }

        // Payment ID not set - payment may still be processing or failed
        Log::warning('PayPal: Payment not yet captured', [
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
