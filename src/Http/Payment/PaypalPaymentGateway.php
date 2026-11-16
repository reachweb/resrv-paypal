<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\CardAttributesBuilder;
use PaypalServerSdkLib\Models\Builders\CardExperienceContextBuilder;
use PaypalServerSdkLib\Models\Builders\CardRequestBuilder;
use PaypalServerSdkLib\Models\Builders\CardVerificationBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PaymentSourceBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\Models\OrdersCardVerificationMethod;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentInterface;
use Reach\StatamicResrv\Mail\OrphanedPaymentNotification;
use Reach\StatamicResrv\Models\Reservation;

class PaypalPaymentGateway implements PaymentInterface
{
    use PaypalApiTrait;

    /** reference_id prefix of an order createOrder() made for the card fields. */
    private const CARD_ORDER_REFERENCE = 'card-';

    protected WebhookSignatureVerifier $webhookVerifier;

    public function name(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function paymentView(): string
    {
        return 'resrv-paypal::livewire.checkout-payment';
    }

    public function supportsManualConfirmation(): bool
    {
        return false;
    }

    public function supportsAutomaticRefunds(): bool
    {
        return true;
    }

    public function __construct(?WebhookSignatureVerifier $webhookVerifier = null)
    {
        // The SDK client is resolved on first PayPal call (PaypalApiTrait::client()), never here:
        // Resrv builds every registered gateway to list them at checkout, so a PayPal without
        // credentials must still construct and only fail when someone actually pays with it.
        $this->webhookVerifier = $webhookVerifier ?? app(WebhookSignatureVerifier::class);
    }

    public function paymentIntent($payment, Reservation $reservation, $data, ?string $returnUrl = null)
    {
        // Inline gateway — $returnUrl is ignored (declared only to model the optional-return-URL
        // convention for redirect gateways; see UPGRADE-PAYMENT-GATEWAYS.md Step 12). The JS SDK
        // sets the post-payment redirect target from the Livewire checkoutCompletedUrl property,
        // which Resrv already points at the right surface (checkout-complete or pay-by-link).
        Log::info('PayPal: Creating order for JS SDK', [
            'reservation_id' => $reservation->id,
            'amount' => $payment->format(),
            'currency' => config('resrv-config.currency_isoCode'),
            'entry_title' => $reservation->entry()->title,
        ]);

        // The PayPal button pays this order. The card fields replace it with a card order on
        // submit (createOrder() with a return URL, via PaypalCaptureController::replaceOrder()).
        $orderId = $this->createOrder($reservation, $payment->format());

        Log::info('PayPal: Order created successfully for JS SDK', [
            'reservation_id' => $reservation->id,
            'order_id' => $orderId,
        ]);

        $paymentIntent = new \stdClass;
        $paymentIntent->id = $orderId;
        // For JS SDK, we pass the order ID as client_secret (used by frontend)
        $paymentIntent->client_secret = $orderId;

        return $paymentIntent;
    }

    /**
     * Create a PayPal order for the reservation and return its id. Without $cardReturnUrl the
     * order has no payment source, which is what the PayPal button pays. With it the order is a
     * card order: 3-D Secure is requested in the create-order body, the only place Card Fields
     * read it (submit() takes no such option), with the configured verification method, and
     * the return and cancel URLs PayPal asks for on every 3-D Secure request. A wallet order and
     * a card order are never the same order: the card payment source has no business on an order
     * the PayPal popup approves. A card order's reference_id marks it (see isCardOrder()).
     *
     * @throws PaypalApiException
     */
    public function createOrder(Reservation $reservation, string $amount, ?string $cardReturnUrl = null): string
    {
        $order = OrderRequestBuilder::init(CheckoutPaymentIntent::CAPTURE, [
            PurchaseUnitRequestBuilder::init(
                AmountWithBreakdownBuilder::init(config('resrv-config.currency_isoCode'), $amount)->build()
            )
                ->referenceId(($cardReturnUrl === null ? '' : self::CARD_ORDER_REFERENCE).$reservation->id)
                // custom_id rides onto the capture webhook (reference_id does not), so verifyPayment() can reconcile it.
                ->customId((string) $reservation->id)
                ->description($reservation->entry()->title)
                ->build(),
        ]);

        if ($cardReturnUrl !== null) {
            $order->paymentSource(PaymentSourceBuilder::init()->card(
                CardRequestBuilder::init()
                    ->attributes(CardAttributesBuilder::init()->verification(
                        CardVerificationBuilder::init()->method(static::scaMethod())->build()
                    )->build())
                    ->experienceContext(CardExperienceContextBuilder::init()
                        ->returnUrl($cardReturnUrl)
                        ->cancelUrl($cardReturnUrl)
                        ->build())
                    ->build()
            )->build());
        }

        return $this->paypalResult($this->client()->getOrdersController()->createOrder(['body' => $order->build()]))->getId();
    }

    /**
     * The configured 3-D Secure method. A blank or unknown value falls back to SCA_WHEN_REQUIRED
     * instead of reaching PayPal: an empty method breaks every card order, and AVS_CVV (which the
     * Orders API also accepts) would switch 3-D Secure off.
     */
    public static function scaMethod(): string
    {
        $method = config('resrv-paypal.sca_method');

        if (in_array($method, [OrdersCardVerificationMethod::SCA_ALWAYS, OrdersCardVerificationMethod::SCA_WHEN_REQUIRED], true)) {
            return $method;
        }

        if (filled($method)) {
            Log::warning('PayPal: PAYPAL_SCA_METHOD must be SCA_ALWAYS or SCA_WHEN_REQUIRED; using SCA_WHEN_REQUIRED', [
                'sca_method' => $method,
            ]);
        }

        return OrdersCardVerificationMethod::SCA_WHEN_REQUIRED;
    }

    /**
     * Fetch the reservation's stored intent so an interrupted payment resumes on the same order
     * instead of minting (and risking a double-charge with) a second one. Used by Resrv's
     * pay-by-link page for manual reservations (HandlesDirectGatewayPayment). Returns null when
     * the intent is genuinely gone, so the caller creates a fresh one.
     *
     * The returned object mirrors paymentIntent()'s JS-SDK shape (id + client_secret both carry
     * the order id) plus a ->status mapped onto the vocabulary the caller's resume logic reads:
     * 'succeeded'/'processing' short-circuit resume (money already moving — do not mint another),
     * 'canceled' forces a fresh intent, and anything else means "still payable — resume it".
     *
     * @throws PaypalApiException on any PayPal error other than a definitive 404 (Step 13)
     */
    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        try {
            $result = $this->paypalResult($this->client()->getOrdersController()->getOrder(['id' => $paymentId]));
        } catch (PaypalApiException $exception) {
            // A 404 means $paymentId is not an order id (or the order expired). PayPal's payment_id
            // flips from the order id to the capture id the moment PaypalCaptureController
            // captures — before the webhook confirms — and the pay-by-link page redirects the
            // customer straight back here. So before reporting the intent as gone (which would mint
            // a SECOND order and let an already-paid customer pay twice), check whether it is now a
            // live capture.
            if ($exception->statusCode === 404) {
                return $this->capturedPaymentAsIntent($paymentId);
            }

            // Transient failure (429, 5xx, auth): propagate so a brownout on this read never
            // replaces a still-payable order with a second one. Transport failures (timeouts)
            // already throw from the SDK.
            throw $exception;
        }

        $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

        // A COMPLETED order was captured, but ORDER-level COMPLETED is not the payment outcome:
        // the capture inside can be DECLINED (ACDC processor decline) or PENDING (eCheck / risk
        // review) — the same split PaypalCaptureController branches on. Landing here with a
        // COMPLETED order means the controller's capture-id persist never happened (client
        // timeout, crash), so the capture's own status must decide: mapping a DECLINED capture
        // to succeeded would strand an unpaid reservation on a permanent "processing" screen
        // with no way to mint a replacement order. VOIDED is dead. Every other order status
        // (CREATED/SAVED/APPROVED/PAYER_ACTION_REQUIRED) is un-captured, and only our capture
        // route can take money for it — so it is payable exactly while that route would capture
        // it, and the same order id is handed back to the JS SDK to finish. That includes a card
        // order: the pay-by-link view swaps a resumed order for a wallet order before the PayPal
        // button pays it, and the card fields mint a fresh card order on every submit. Reporting
        // it canceled instead would let a concurrent pay() write a new order over a capture
        // landing on this one.
        return $this->intentForResume($paymentId, match ($status) {
            'COMPLETED' => $this->captureResumeStatus($this->orderCaptureStatus($result)),
            'VOIDED' => 'canceled',
            default => $this->orderCanStillBeCaptured($reservation) ? 'requires_payment_method' : 'canceled',
        });
    }

    /**
     * Whether PaypalCaptureController may still capture an un-captured order for this reservation.
     * PayPal has no void for CAPTURE-intent orders, so this is what voiding means here: once the
     * reservation has left PENDING / AWAITING_PAYMENT (confirmed out of band, cancelled, expired,
     * refunded) the route refuses the order, and it can never take money. The route checks this
     * under the reservation's row lock for the whole capture, so a transition either commits
     * first (and the capture is refused) or waits for the capture (and then reads it as moving
     * money). Reporting such orders as 'canceled' is what lets Resrv verify the void after
     * cancelPaymentIntent() instead of alerting admins about a still-payable intent.
     */
    public function orderCanStillBeCaptured(Reservation $reservation): bool
    {
        return in_array($reservation->status, [
            ReservationStatus::PENDING->value,
            ReservationStatus::AWAITING_PAYMENT->value,
        ], true);
    }

    /**
     * Extract the status of a COMPLETED order's capture, mirroring PaypalCaptureController's
     * drill-down. Null when the capture is missing/unreadable — captureResumeStatus() then
     * reports processing, which the confirming webhook (or its custom_id fallback) resolves.
     */
    protected function orderCaptureStatus($order): ?string
    {
        $purchaseUnits = is_array($order)
            ? ($order['purchase_units'] ?? [])
            : ($order->getPurchaseUnits() ?? []);

        $purchaseUnit = $purchaseUnits[0] ?? null;

        $payments = is_array($purchaseUnit)
            ? ($purchaseUnit['payments'] ?? null)
            : $purchaseUnit?->getPayments();

        $captures = is_array($payments)
            ? ($payments['captures'] ?? [])
            : ($payments?->getCaptures() ?? []);

        $capture = $captures[0] ?? null;

        return is_array($capture) ? ($capture['status'] ?? null) : $capture?->getStatus();
    }

    /**
     * Whether createOrder() made this order for the card fields, so it carries the 3-D Secure
     * request. PayPal echoes the reference_id createOrder() set; the browser cannot change it.
     */
    public static function isCardOrder(Order $order): bool
    {
        return str_starts_with((string) ($order->getPurchaseUnits()[0] ?? null)?->getReferenceId(), self::CARD_ORDER_REFERENCE);
    }

    /**
     * Map a capture status onto the caller's resume vocabulary. DECLINED and FAILED are terminal
     * at PayPal — that capture can never complete and no money moved — so they map to canceled,
     * telling the caller to replace the intent with a fresh order (mirroring the webhook's
     * DENIED/DECLINED stance that a failed attempt is retryable). Everything else is money
     * moving or settling: succeeded/processing short-circuit resume so a duplicate order is
     * never minted.
     */
    protected function captureResumeStatus(?string $status): string
    {
        return match ($status) {
            'COMPLETED' => 'succeeded',
            'DECLINED', 'FAILED' => 'canceled',
            default => 'processing',
        };
    }

    /**
     * Resolve a payment_id that is no longer an order id as a capture. A stored capture id
     * usually means the customer already paid and only the confirming webhook is outstanding;
     * captureResumeStatus() maps the capture's fate onto the resume vocabulary. Returns null
     * when it is neither an order nor a capture (genuinely gone); transient failures propagate.
     */
    protected function capturedPaymentAsIntent(string $paymentId): ?object
    {
        try {
            $result = $this->paypalResult($this->client()->getPaymentsController()->getCapturedPayment(['captureId' => $paymentId]));
        } catch (PaypalApiException $exception) {
            if ($exception->statusCode === 404) {
                return null;
            }

            throw $exception;
        }

        $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

        return $this->intentForResume($paymentId, $this->captureResumeStatus($status));
    }

    protected function intentForResume(string $paymentId, string $status): object
    {
        $intent = new \stdClass;
        $intent->id = $paymentId;
        $intent->client_secret = $paymentId;
        $intent->status = $status;

        return $intent;
    }

    public function cancelPaymentIntent(string $paymentId, Reservation $reservation): void
    {
        // $paymentId is an un-captured PayPal order. The v2 Orders API has no cancel/void for
        // CAPTURE-intent orders (they just expire), and none is needed: only our capture route can
        // take money for one, and it refuses once Resrv has cleared payment_id or moved the
        // reservation out of a payable state (see orderCanStillBeCaptured()).
        Log::info('PayPal: Payment intent abandoned — no action needed (the capture route no longer accepts it)', [
            'reservation_id' => $reservation->id,
            'order_id' => $paymentId,
        ]);
    }

    public function refund(Reservation $reservation)
    {
        // This runs inside the REFUNDED transition's database transaction (a row lock is
        // held), so keep it down to the single provider call.
        //
        // An empty refund body refunds the full remaining capture — payment + surcharge,
        // exactly what was charged and what the customer-cancellation email promises. A
        // partial refund of only `payment` would strand the surcharge at PayPal.
        //
        // The stable PayPal-Request-Id makes the call idempotent: if the connection drops
        // after PayPal processed the refund (rolling the status transition back), a retry
        // replays the original refund instead of failing forever as already-refunded. The
        // capture id is part of the key so a reservation that legitimately creates a second
        // capture never replays a stale request. The variable part is hashed because PayPal
        // caps PayPal-Request-Id at 38 single-byte characters — concatenating the raw ids
        // overruns it once the reservation id reaches eight digits.
        try {
            // The client is resolved in here so missing credentials also surface as
            // RefundFailedException, like every other reason the refund cannot run.
            $refund = $this->paypalResult($this->client()->getPaymentsController()->refundCapturedPayment([
                'captureId' => $reservation->payment_id,
                'paypalRequestId' => $this->refundRequestId($reservation),
                'body' => RefundRequestBuilder::init()->build(),
            ]));
        } catch (PaypalApiException $exception) {
            // Past PayPal's idempotency window — or after an out-of-band dashboard refund —
            // the retry is rejected as already fully refunded. The money is exactly where
            // the caller wants it, so treat it as success: throwing would roll back the
            // REFUNDED transition and strand a refunded charge on a live reservation.
            if ($exception->hasIssue('CAPTURE_FULLY_REFUNDED')) {
                return true;
            }

            // Every PayPal error response (validation, auth, rate limit, 5xx) must surface as
            // RefundFailedException so callers roll back the status transition and show their
            // refund-failed message instead of reporting money returned that never moved.
            throw new RefundFailedException($exception->getMessage(), 0, $exception);
        } catch (\Exception $exception) {
            // Transport failures (timeout, connection) the SDK does throw.
            throw new RefundFailedException($exception->getMessage(), 0, $exception);
        }

        // A 2xx can still carry a refund PayPal could not process. PENDING (e.g. an eCheck
        // capture) is money in flight back to the customer, so it counts as refunded.
        $status = is_object($refund) && method_exists($refund, 'getStatus') ? $refund->getStatus() : null;

        if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
            throw new RefundFailedException("PayPal reported the refund as {$status}.");
        }

        return $refund;
    }

    /**
     * Stable per-(reservation, capture) idempotency key, kept within PayPal's 38-single-byte
     * limit for PayPal-Request-Id: 9-char prefix + 29 hex chars of a hash over both ids.
     */
    protected function refundRequestId(Reservation $reservation): string
    {
        return 'resrv-rf-'.substr(hash('sha256', $reservation->id.'-'.$reservation->payment_id), 0, 29);
    }

    public function getPublicKey(Reservation $reservation)
    {
        // Resrv's Checkout stores this in a string property before it creates the order. A null
        // (PAYPAL_CLIENT_ID unset) would TypeError there, before client() reports what's missing.
        return (string) config('services.paypal.client_id');
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
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        // The JS returns the customer with the capture id PaypalCaptureController just stored,
        // the way Stripe returns its payment_intent id. The reservation is resolved by that
        // unguessable id — never by the row id, which anyone can enumerate on this public page to
        // read other customers' reservations.
        $captureId = request()->input('capture_id');

        Log::info('PayPal: Handling redirect back (JS SDK flow)', [
            'capture_id' => $captureId,
            'ip' => request()->ip(),
        ]);

        // Resolve gracefully — a missing or tampered id must not 500 the checkout-complete page.
        $reservation = is_string($captureId) && $captureId !== ''
            ? Reservation::findByPaymentId($captureId)->first()
            : null;

        if (! $reservation) {
            Log::warning('PayPal: Redirect back could not resolve a reservation', [
                'capture_id' => $captureId,
            ]);

            return [
                'status' => false,
                'reservation' => [],
            ];
        }

        // The webhook is the source of truth for confirmation, but it is asynchronous. To show the
        // customer an accurate result immediately, re-verify the capture with PayPal (mirroring the
        // Stripe gateway, which re-reads the PaymentIntent).
        if ($this->captureIsCompleted($reservation)) {
            Log::info('PayPal: Payment verified via capture lookup', [
                'reservation_id' => $reservation->id,
                'capture_id' => $reservation->payment_id,
            ]);

            return [
                'status' => true,
                'reservation' => $reservation->toArray(),
            ];
        }

        Log::warning('PayPal: Payment not captured at redirect back', [
            'reservation_id' => $reservation->id,
        ]);

        return [
            'status' => false,
            'reservation' => $reservation->toArray(),
        ];
    }

    /**
     * Confirm with PayPal that the reservation's stored capture actually completed.
     *
     * Returns false (without throwing) when the capture did not complete. When PayPal cannot be
     * read (error response or transport failure) it falls back to a reservation already marked
     * CONFIRMED by the webhook so a transient API blip can't mask a genuinely paid booking.
     */
    protected function captureIsCompleted(Reservation $reservation): bool
    {
        if (! $reservation->payment_id) {
            return false;
        }

        try {
            $result = $this->paypalResult($this->client()->getPaymentsController()->getCapturedPayment([
                'captureId' => $reservation->payment_id,
            ]));

            $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

            return $status === 'COMPLETED';
        } catch (\Throwable $e) {
            Log::info('PayPal: Capture lookup failed at redirect back; falling back to reservation status', [
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);

            return $reservation->status === ReservationStatus::CONFIRMED->value;
        }
    }

    public function handlePaymentPending(): bool|array
    {
        if (! request()->has('payment_pending')) {
            return false;
        }

        $reservation = Reservation::find(request()->input('payment_pending'));

        return [
            'status' => 'pending',
            'reservation' => $reservation ? $reservation->toArray() : [],
        ];
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
        $eventId = $data['id'] ?? null;

        $captureEvents = [
            'PAYMENT.CAPTURE.COMPLETED',
            'PAYMENT.CAPTURE.DECLINED',
            'PAYMENT.CAPTURE.DENIED',
            'PAYMENT.CAPTURE.PENDING',
            'PAYMENT.CAPTURE.REFUNDED',
            'PAYMENT.CAPTURE.REVERSED',
        ];

        if (! in_array($eventType, $captureEvents, true)) {
            Log::info('PayPal: Webhook event type not handled', [
                'event_type' => $eventType,
            ]);

            return response()->json([], 200);
        }

        // For refund events, resource.id is the refund ID — extract capture ID from links
        if ($eventType === 'PAYMENT.CAPTURE.REFUNDED') {
            $captureId = null;
            foreach ($data['resource']['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') === 'up' && preg_match('#/captures/([A-Z0-9]+)$#', $link['href'] ?? '', $matches)) {
                    $captureId = $matches[1];
                    break;
                }
            }
        } else {
            $captureId = $data['resource']['id'] ?? null;
        }

        if (! $captureId) {
            Log::warning('PayPal: Webhook missing capture ID', [
                'event_type' => $eventType,
                'resource_id' => $data['resource']['id'] ?? null,
            ]);

            return response()->json([], 200);
        }

        $reservation = Reservation::findByPaymentId($captureId)->first();

        // Capture-ID fallback via custom_id: findByPaymentId misses in two cases —
        //   1. The customer abandoned the intent: Checkout::cancelActiveIntent / expire() set
        //      payment_id to '' (truly stale — the charge is orphaned, must NOT confirm).
        //   2. The capture endpoint simply hasn't overwritten the order ID with the capture ID yet
        //      (it races this webhook). payment_id is still the (non-empty) order ID — this is a LIVE
        //      capture we should confirm normally.
        // custom_id carries the reservation id onto the capture (reference_id does not), so recover the
        // reservation from there and only treat it as stale when payment_id was actually cleared.
        $isStaleIntent = false;
        $foundByCustomId = false;

        if (! $reservation) {
            $referenceId = $data['resource']['custom_id'] ?? null;

            if ($referenceId) {
                $reservation = Reservation::find($referenceId);
                $foundByCustomId = (bool) $reservation;
                $isStaleIntent = $reservation
                    ? in_array($reservation->payment_id, ['', null], true)
                    : false;
            }
        }

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
            'reservation_status' => $reservation->status,
            'is_stale_intent' => $isStaleIntent,
        ]);

        // status is a plain string column in Resrv v6 (not an enum cast), so compare against ->value.
        if ($reservation->status === ReservationStatus::CONFIRMED->value) {
            // Found through custom_id, so payment_id is not this capture: another payment already
            // paid this booking, and this one charged the customer a second time. E.g. a capture
            // that timed out at the capture route and completed after the customer paid a
            // replacement order. A redelivery of the paying capture is found by payment_id.
            if ($eventType === 'PAYMENT.CAPTURE.COMPLETED' && $foundByCustomId) {
                Log::warning('PayPal: Capture completed for a reservation another payment already confirmed — manual refund likely required.', [
                    'reservation_id' => $reservation->id,
                    'capture_id' => $captureId,
                    'current_payment_id' => $reservation->payment_id,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $captureId, $eventId);

                return response()->json([], 200);
            }

            Log::info('PayPal: Webhook skipped - reservation already confirmed', [
                'reservation_id' => $reservation->id,
                'capture_id' => $captureId,
            ]);

            return response()->json([], 200);
        }

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            // Stale intent: the customer walked away (back, gateway switch, expiry) before this
            // capture landed. The charge is orphaned — confirming now would email/decrease inventory
            // against an amount or gateway the reservation no longer matches. Notify and stop.
            if ($isStaleIntent) {
                Log::warning('PayPal: Capture completed after the intent was abandoned — manual reconciliation may be required.', [
                    'reservation_id' => $reservation->id,
                    'capture_id' => $captureId,
                    'current_payment_id' => $reservation->payment_id,
                    'current_payment_gateway' => $reservation->payment_gateway,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $captureId, $eventId);

                return response()->json([], 200);
            }

            // Terminal reservation (expired/refunded/partner): the charge can't attach to a live
            // booking, so notify admins and stop.
            if (OrphanedPaymentNotification::notifyIfOrphaned($reservation, $captureId, $eventId)) {
                return response()->json([], 200);
            }

            // Defense-in-depth: refuse to confirm if the captured amount/currency no longer matches
            // what's owed (payment + surcharge). Guards against a coupon/total change between intent
            // creation and capture. Comparison goes through Resrv's currency-aware Price (minor units),
            // so it is correct for 0- and 3-decimal currencies (JPY, BHD, KWD) — a naive ×100 is not.
            $amount = $data['resource']['amount'] ?? [];
            $capturedValue = $amount['value'] ?? null;
            $capturedCurrency = $amount['currency_code'] ?? null;
            $expected = $reservation->payment->add($reservation->payment_surcharge);
            $expectedCurrency = config('resrv-config.currency_isoCode');

            $amountVerified = true;

            if ($capturedValue !== null) {
                try {
                    $amountVerified = Price::create($capturedValue)->equals($expected)
                        && ($capturedCurrency === null || $capturedCurrency === $expectedCurrency);
                } catch (\Throwable $e) {
                    // Unparseable amount — fail closed (do not confirm).
                    $amountVerified = false;
                }
            } elseif ($capturedCurrency !== null && $capturedCurrency !== $expectedCurrency) {
                $amountVerified = false;
            }

            if (! $amountVerified) {
                Log::warning('PayPal: Captured amount/currency does not match the reservation total; not confirming.', [
                    'reservation_id' => $reservation->id,
                    'capture_id' => $captureId,
                    'captured_value' => $capturedValue,
                    'captured_currency' => $capturedCurrency,
                    'expected_value' => $expected->format(),
                    'expected_currency' => $expectedCurrency,
                ]);

                OrphanedPaymentNotification::dispatchFor($reservation, $captureId, $eventId);

                return response()->json([], 200);
            }

            // Route confirmation through transitionTo so a concurrent ExpireReservations (which runs
            // on every availability search) can't be clobbered: it re-reads the row under a lock and
            // returns false if the row moved to an incompatible state.
            // Found through custom_id: payment_id still holds the order id because the capture
            // route never stored the capture id (its PayPal call timed out after PayPal captured,
            // or the process died). Store it in the same write as CONFIRMED — refunds and every
            // later lookup key on the capture id, and an order id refund 404s at PayPal.
            $storeCaptureId = function (Reservation $fresh) use ($foundByCustomId, $captureId) {
                if ($foundByCustomId) {
                    $fresh->payment_id = $captureId;
                }
            };

            if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true, inTransaction: $storeCaptureId)) {
                // Core's LogReservationConfirmed listener records the confirmation (and the
                // activity log stores the payment facts), so no gateway-side logging here.
                // 'webhook' is ReservationConfirmed::VIA_WEBHOOK spelled out: the constant does
                // not exist on Resrv 6.0.0 (still allowed by composer), where referencing it
                // would fatal — 6.0.0 simply ignores the extra constructor arguments.
                ReservationConfirmed::dispatch($reservation, 'webhook', [
                    'gateway' => $reservation->payment_gateway ?: 'paypal',
                    'payment_id' => $captureId,
                ]);

                return response()->json([], 200);
            }

            // Lost the confirm race (the row expired under the lock): surface any orphaned charge.
            $reservation->refresh();
            OrphanedPaymentNotification::notifyIfOrphaned($reservation, $captureId, $eventId);

            return response()->json([], 200);
        }

        if ($eventType === 'PAYMENT.CAPTURE.PENDING') {
            // Capture is pending (e.g. risk review). Leave the reservation PENDING; a later COMPLETED
            // or DENIED webhook resolves it.
            Log::info('PayPal: Capture pending - leaving reservation PENDING', [
                'reservation_id' => $reservation->id,
                'capture_id' => $captureId,
            ]);

            return response()->json([], 200);
        }

        if ($eventType === 'PAYMENT.CAPTURE.DENIED' || $eventType === 'PAYMENT.CAPTURE.DECLINED') {
            // A denied/declined capture is a failed *attempt* — keep the reservation PENDING so the
            // customer can retry. ExpireReservations reclaims the hold if they abandon, mirroring how
            // the Stripe gateway treats payment_intent.payment_failed.
            Log::info('PayPal: Capture denied/declined - leaving reservation PENDING for retry', [
                'reservation_id' => $reservation->id,
                'capture_id' => $captureId,
                'event_type' => $eventType,
            ]);

            return response()->json([], 200);
        }

        // PAYMENT.CAPTURE.REFUNDED / PAYMENT.CAPTURE.REVERSED: money was returned. Resrv owns the
        // refund lifecycle — admin/customer-initiated refunds transition the reservation and restore
        // availability through the CP/self-service flow — so, mirroring the bundled Stripe gateway
        // (which does not act on refund webhooks), we only acknowledge here. An externally-initiated
        // PayPal-dashboard refund is logged for manual reconciliation.
        Log::info('PayPal: Refund/reversal webhook acknowledged (no state change)', [
            'reservation_id' => $reservation->id,
            'capture_id' => $captureId,
            'event_type' => $eventType,
        ]);

        return response()->json([], 200);
    }

    public function verifyWebhook()
    {
        return true;
    }
}
