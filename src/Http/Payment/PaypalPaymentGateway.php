<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Exceptions\ApiException;
use PaypalServerSdkLib\Exceptions\ErrorException;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\PaypalServerSdkClient;
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

    protected PaypalServerSdkClient $client;

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
        $this->client = app(PaypalServerSdkClient::class);
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
                // custom_id rides onto the capture webhook (reference_id does not), so verifyPayment() can reconcile it.
                ->customId((string) $reservation->id)
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
     */
    public function retrievePaymentIntent(string $paymentId, Reservation $reservation): ?object
    {
        try {
            $result = $this->client->getOrdersController()
                ->getOrder(['id' => $paymentId])
                ->getResult();
        } catch (ApiException $exception) {
            // A 404 means $paymentId is not an order id. PayPal's payment_id flips from the order
            // id to the capture id the moment PaypalCaptureController captures — before the webhook
            // confirms — and the pay-by-link page redirects the customer straight back here. So
            // before reporting the intent as gone (which would mint a SECOND order and let an
            // already-paid customer pay twice), check whether it is now a live capture.
            if ($exception->getCode() === 404) {
                return $this->capturedPaymentAsIntent($paymentId);
            }

            // Transient failure (timeout, 429, 5xx, auth): propagate so a brownout on this read
            // never replaces a still-payable order with a second one.
            throw $exception;
        }

        $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

        // COMPLETED (already captured) and VOIDED (dead) short-circuit resume; every other order
        // status (CREATED/SAVED/APPROVED/PAYER_ACTION_REQUIRED) is still payable, so the same
        // order id is handed back to the JS SDK to finish.
        return $this->intentForResume($paymentId, match ($status) {
            'COMPLETED' => 'succeeded',
            'VOIDED' => 'canceled',
            default => 'requires_payment_method',
        });
    }

    /**
     * Resolve a payment_id that is no longer an order id as a capture. A stored capture id
     * usually means the customer already paid and only the confirming webhook is outstanding, so
     * money-adjacent statuses report succeeded/processing and the caller never mints a duplicate
     * order. DECLINED and FAILED are the exception: they are terminal at PayPal — that capture
     * can never complete and no money moved — so they map to canceled, telling the caller to
     * replace the intent with a fresh order (mirroring the webhook's DENIED/DECLINED stance that
     * a failed attempt is retryable) instead of parking the customer on a permanent "processing".
     * Returns null when it is neither an order nor a capture (genuinely gone); transient
     * failures propagate.
     */
    protected function capturedPaymentAsIntent(string $paymentId): ?object
    {
        try {
            $result = $this->client->getPaymentsController()
                ->getCapturedPayment(['captureId' => $paymentId])
                ->getResult();
        } catch (ApiException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }

        $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

        return $this->intentForResume($paymentId, match ($status) {
            'COMPLETED' => 'succeeded',
            'DECLINED', 'FAILED' => 'canceled',
            default => 'processing',
        });
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
        // CAPTURE-intent orders (they just expire), so this is a no-op we only log for traceability.
        Log::info('PayPal: Payment intent abandoned — no action needed (un-captured order expires automatically)', [
            'reservation_id' => $reservation->id,
            'order_id' => $paymentId,
        ]);
    }

    public function refund(Reservation $reservation)
    {
        // This runs inside the REFUNDED transition's database transaction (a row lock is
        // held), so keep it down to the single provider call.
        $paymentsController = $this->client->getPaymentsController();

        // An empty refund body refunds the full remaining capture — payment + surcharge,
        // exactly what was charged and what the customer-cancellation email promises. A
        // partial refund of only `payment` would strand the surcharge at PayPal.
        //
        // The stable PayPal-Request-Id makes the call idempotent: if the connection drops
        // after PayPal processed the refund (rolling the status transition back), a retry
        // replays the original refund instead of failing forever as already-refunded. The
        // capture id is part of the key so a reservation that legitimately creates a second
        // capture never replays a stale request.
        try {
            $response = $paymentsController->refundCapturedPayment([
                'captureId' => $reservation->payment_id,
                'paypalRequestId' => 'resrv-refund-'.$reservation->id.'-'.$reservation->payment_id,
                'body' => RefundRequestBuilder::init()->build(),
            ]);

            return $response->getResult();
        } catch (\Exception $exception) {
            // Past PayPal's idempotency window — or after an out-of-band dashboard refund —
            // the retry is rejected as already fully refunded. The money is exactly where
            // the caller wants it, so treat it as success: throwing would roll back the
            // REFUNDED transition and strand a refunded charge on a live reservation.
            if ($this->captureAlreadyFullyRefunded($exception)) {
                return true;
            }

            // Every PayPal failure mode (validation, auth, rate limit, connection) must
            // surface as RefundFailedException so callers roll back the status transition
            // and show their refund-failed message instead of a 500.
            throw new RefundFailedException($exception->getMessage());
        }
    }

    /**
     * Whether a failed refund call was rejected because the capture is already fully
     * refunded (PayPal issue code CAPTURE_FULLY_REFUNDED on the 422 response).
     */
    protected function captureAlreadyFullyRefunded(\Exception $exception): bool
    {
        if (! $exception instanceof ErrorException) {
            return false;
        }

        foreach ($exception->getDetails() ?? [] as $detail) {
            if ($detail->getIssue() === 'CAPTURE_FULLY_REFUNDED') {
                return true;
            }
        }

        return false;
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
        if ($pending = $this->handlePaymentPending()) {
            return $pending;
        }

        $id = request()->input('id');
        $cancelled = request()->input('cancelled');

        Log::info('PayPal: Handling redirect back (JS SDK flow)', [
            'reservation_id' => $id,
            'cancelled' => $cancelled ? 'yes' : 'no',
            'ip' => request()->ip(),
        ]);

        // Resolve gracefully — a missing or tampered id must not 500 the checkout-complete page.
        $reservation = $id ? Reservation::find($id) : null;

        if (! $reservation) {
            Log::warning('PayPal: Redirect back could not resolve a reservation', [
                'reservation_id' => $id,
            ]);

            return [
                'status' => false,
                'reservation' => [],
            ];
        }

        if ($cancelled) {
            Log::info('PayPal: User cancelled payment', [
                'reservation_id' => $reservation->id,
            ]);

            return [
                'status' => false,
                'reservation' => $reservation->toArray(),
            ];
        }

        // The webhook is the source of truth for confirmation, but it is asynchronous. To show the
        // customer an accurate result immediately, re-verify the capture with PayPal (mirroring the
        // Stripe gateway, which re-reads the PaymentIntent). payment_id holds the capture ID once the
        // capture endpoint has run; before that it still holds the un-captured order ID, for which
        // getCapturedPayment() 404s — correctly yielding a failure result instead of a false success.
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
     * Returns false (without throwing) when payment_id is empty, still an un-captured order ID, or
     * PayPal is unreachable — in the unreachable case we fall back to a reservation already marked
     * CONFIRMED by the webhook so a transient API blip can't mask a genuinely paid booking.
     */
    protected function captureIsCompleted(Reservation $reservation): bool
    {
        if (! $reservation->payment_id) {
            return false;
        }

        try {
            $response = $this->client->getPaymentsController()->getCapturedPayment([
                'captureId' => $reservation->payment_id,
            ]);

            $result = $response->getResult();
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

        if (! $reservation) {
            $referenceId = $data['resource']['custom_id'] ?? null;

            if ($referenceId) {
                $reservation = Reservation::find($referenceId);
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
            if ($reservation->transitionTo(ReservationStatus::CONFIRMED, tolerant: true)) {
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
