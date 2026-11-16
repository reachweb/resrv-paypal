<?php

namespace Reach\ResrvPaymentPaypal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\Models\Order;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalApiException;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalApiTrait;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;

class PaypalCaptureController extends Controller
{
    use PaypalApiTrait;

    public function capture(Request $request, string $orderId): JsonResponse
    {
        Log::info('PayPal: Capture request received', [
            'order_id' => $orderId,
            'ip' => $request->ip(),
        ]);

        $reservation = $this->payableReservation($request, $orderId);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        // Read before taking the row lock, which then covers only the capture call: whether the
        // order is approved and how its 3-D Secure went is PayPal's state, not the row's.
        try {
            $order = $this->paypalResult($this->client()->getOrdersController()->getOrder(['id' => $orderId]));
        } catch (\Throwable $exception) {
            Log::error('PayPal: Could not read the order before capture', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->captureFailed();
        }

        // Captured by an earlier attempt whose response was lost: report that capture.
        if ($order->getStatus() === 'COMPLETED') {
            return $this->underLock($request, $reservation, $orderId, fn (Reservation $fresh) => $this->respondToOrder($fresh, $orderId, $order));
        }

        if ($refused = $this->refuseUncapturableOrder($reservation, $orderId, $order)) {
            return $refused;
        }

        return $this->underLock($request, $reservation, $orderId, fn (Reservation $fresh) => $this->captureLocked($fresh, $orderId));
    }

    /**
     * Replace the reservation's order with a fresh one for the funding source the customer is
     * about to pay with: 'card' when the card fields submit (a card order carrying the 3-D Secure
     * request), 'paypal' when the PayPal button opens on an order that is not a wallet order. The
     * new order becomes payment_id under the row lock, so the order it replaces can no longer be
     * captured. Authorised exactly like a capture of the current order.
     */
    public function replaceOrder(Request $request, string $orderId, string $source): JsonResponse
    {
        $reservation = $this->payableReservation($request, $orderId);

        if ($reservation instanceof JsonResponse) {
            return $reservation;
        }

        // Created before taking the row lock, as Resrv creates intents. If the lock then refuses,
        // the new order was never stored: nothing can capture it, and it expires at PayPal.
        try {
            $newOrderId = app(PaypalPaymentGateway::class)->createOrder(
                $reservation,
                // Payment + surcharge: what both payment surfaces charge and what the webhook's
                // amount guard expects.
                $reservation->payment->add($reservation->payment_surcharge)->format(),
                // The page the customer pays on. Card Fields run 3-D Secure in PayPal's own
                // modal, so PayPal is not expected to send the customer here.
                $source === 'card' ? url()->previous() : null,
            );
        } catch (\Throwable $exception) {
            return $this->replacementFailed($reservation, $orderId, $source, $exception);
        }

        return $this->underLock($request, $reservation, $orderId, function (Reservation $fresh) use ($request, $orderId, $source, $newOrderId) {
            // payment_id still names $orderId, but a capture of it can reach PayPal without its
            // capture id being stored (a lost capture response). Every capture holds this lock
            // while it calls PayPal, so the order read here is settled: replacing a captured order
            // would let the customer pay twice.
            try {
                $order = $this->paypalResult($this->client()->getOrdersController()->getOrder(['id' => $orderId]));
            } catch (\Throwable $exception) {
                // A 404 as well: payment_id is then a stored capture id, not an order to replace.
                return $this->replacementFailed($fresh, $orderId, $source, $exception);
            }

            if ($order->getStatus() === 'COMPLETED') {
                return $this->respondToOrder($fresh, $orderId, $order);
            }

            if (! in_array($order->getStatus(), ['CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED'], true)) {
                return $this->invalidOrder($request, $orderId, $fresh);
            }

            $fresh->update(['payment_id' => $newOrderId]);

            Log::info('PayPal: Order replaced for the chosen funding source', [
                'reservation_id' => $fresh->id,
                'replaced_order_id' => $orderId,
                'order_id' => $newOrderId,
                'source' => $source,
                'ip' => $request->ip(),
            ]);

            return response()->json(['id' => $newOrderId]);
        });
    }

    protected function replacementFailed(Reservation $reservation, string $orderId, string $source, \Throwable $exception): JsonResponse
    {
        Log::error('PayPal: Could not replace the order', [
            'order_id' => $orderId,
            'reservation_id' => $reservation->id,
            'source' => $source,
            'error' => $exception->getMessage(),
        ]);

        return response()->json([
            'error' => 'Order failed',
            'message' => 'PayPal could not start the payment. Please try again.',
        ], 502);
    }

    /**
     * The reservation $orderId pays, when this request may pay it; 403 otherwise. Read without a
     * lock so a refused request costs no PayPal call; underLock() checks again before any write.
     */
    protected function payableReservation(Request $request, string $orderId): Reservation|JsonResponse
    {
        // Security: the order ID is an unguessable capability token bound to exactly one
        // reservation by findByPaymentId. It authorises a capture, or a replacement of the order,
        // in either legitimate context:
        //
        //  1. Normal inline checkout — the customer's session owns the in-flight PENDING
        //     reservation (Resrv stores its id under 'resrv_reservation'). Bind to it as
        //     defence-in-depth so a leaked order ID can't be captured from another browser context.
        //
        //  2. Pay-by-link for admin-created (manual) reservations — these are created viaCp, so
        //     AddReservationIdToSession deliberately never seeds the session, and the customer
        //     reaches the pay page through an HMAC deep link in a fresh browser. There is no
        //     checkout session to bind to; the order ID is the capability. Only the CP manual-
        //     reservation flow ever produces AWAITING_PAYMENT, so this never loosens the guard for
        //     a normal PENDING checkout. Past hold_expires_at the reservation is no longer payable
        //     even though the lapsed-hold sweep may not have cancelled it yet
        //     (ReservationPayment::deadlinePassed), so a popup opened before the deadline must
        //     not capture after it.
        $reservation = Reservation::findByPaymentId($orderId)->first();

        if (! $reservation || ! $this->mayPay($request, $reservation, $orderId)) {
            return $this->invalidOrder($request, $orderId, $reservation);
        }

        return $reservation;
    }

    /**
     * Run $then with the reservation re-read under its row lock, when this request may still pay
     * $orderId; 403 otherwise. PayPal has no void for CAPTURE-intent orders, so refusing here is
     * what voids one: a transition that changes payability (admin marks paid or cancels, the
     * lapsed-hold sweep, expiry, a checkout that replaced the intent) either commits first — and
     * this request is refused — or waits for it and then sees the stored capture id as money
     * already moving. Without the lock a capture could land between Resrv verifying the "void"
     * and its commit, unnoticed. A capture holds the lock while it calls PayPal's capture (and,
     * after ORDER_ALREADY_CAPTURED, reads the order); a replacement while it reads the order it
     * replaces. Each call is bounded by the SDK client's 15s timeout, as for refunds.
     */
    protected function underLock(Request $request, Reservation $reservation, string $orderId, \Closure $then): JsonResponse
    {
        return DB::transaction(function () use ($request, $reservation, $orderId, $then) {
            $fresh = Reservation::query()->lockForUpdate()->find($reservation->id);

            if (! $fresh || ! $this->mayPay($request, $fresh, $orderId)) {
                return $this->invalidOrder($request, $orderId, $fresh ?? $reservation);
            }

            return $then($fresh);
        });
    }

    /**
     * Whether this request may pay $orderId: it is the reservation's current order on a PayPal
     * reservation (another gateway's intent id would let the replace route mint a PayPal order
     * around that gateway's surcharge, amount limits and refunds), and the reservation passes the
     * rule PaypalPaymentGateway::orderCanStillBeCaptured() reports to Resrv plus the per-request
     * binding: a PENDING checkout only from its own session, an awaiting-payment reservation only
     * before its hold deadline.
     */
    protected function mayPay(Request $request, Reservation $reservation, string $orderId): bool
    {
        if ($reservation->payment_id !== $orderId || ! $this->isPaypalReservation($reservation)) {
            return false;
        }

        return match ($reservation->status) {
            ReservationStatus::PENDING->value => $this->sessionOwns($request, $reservation),
            ReservationStatus::AWAITING_PAYMENT->value => ! $reservation->holdDeadlinePassed(),
            default => false,
        };
    }

    protected function isPaypalReservation(Reservation $reservation): bool
    {
        try {
            return $reservation->resolvePaymentGateway() instanceof PaypalPaymentGateway;
        } catch (\Throwable) {
            // A gateway that is no longer configured.
            return false;
        }
    }

    protected function sessionOwns(Request $request, Reservation $reservation): bool
    {
        return (string) $request->session()->get('resrv_reservation') === (string) $reservation->id;
    }

    protected function invalidOrder(Request $request, string $orderId, ?Reservation $reservation): JsonResponse
    {
        Log::warning('PayPal: Request refused — order not payable for this request', [
            'order_id' => $orderId,
            'reservation_id' => $reservation?->id,
            'payment_id' => $reservation?->payment_id,
            'payment_gateway' => $reservation?->payment_gateway,
            'status' => $reservation?->status,
            'session_owns_reservation' => $reservation ? $this->sessionOwns($request, $reservation) : null,
            'hold_expires_at' => $reservation?->hold_expires_at?->toIso8601String(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['error' => 'Invalid order'], 403);
    }

    protected function captureLocked(Reservation $reservation, string $orderId): JsonResponse
    {
        Log::info('PayPal: Capturing order via API', [
            'order_id' => $orderId,
            'reservation_id' => $reservation->id,
        ]);

        try {
            // PayPal documents the default Prefer: return=minimal response as id, status and links
            // only; the capture id and status live in purchase_units[].payments.captures, which
            // only the full representation is documented to carry.
            $order = $this->paypalResult($this->client()->getOrdersController()->captureOrder([
                'id' => $orderId,
                'prefer' => 'return=representation',
            ]));
        } catch (PaypalApiException $exception) {
            if ($exception->hasIssue('ORDER_ALREADY_CAPTURED')) {
                // A retry after the first attempt's response was lost: that attempt captured, so
                // report its capture instead of telling a paying customer they were declined.
                return $this->respondWithCapturedOrder($reservation, $orderId);
            }

            Log::warning('PayPal: Capture rejected by PayPal', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'http_status' => $exception->statusCode,
                'issues' => $exception->issues,
                'debug_id' => $exception->debugId,
            ]);

            // 422 is PayPal refusing this payment (INSTRUMENT_DECLINED, PAYER_ACTION_REQUIRED, ...).
            // The order is still approvable, so the customer can retry with another method.
            if ($exception->statusCode === 422) {
                return $this->declined();
            }

            return $this->captureFailed();
        } catch (\Throwable $exception) {
            // Transport failure (timeout, connection). PayPal may still have captured: a retry of
            // this route or of the replace route reads the order as COMPLETED and reports (and
            // stores) its capture instead of taking a second payment, and the webhook confirms
            // either way.
            Log::error('PayPal: Capture API error', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->captureFailed();
        }

        return $this->respondToOrder($reservation, $orderId, $order);
    }

    /**
     * Refuse an un-captured order that must not be captured. PayPal captures a card order
     * whatever its 3-D Secure outcome; whether to go ahead is the merchant's call.
     *
     *  - Only a CREATED or APPROVED order is captured. A card order reads CREATED once its
     *    challenge finishes (the card fields never move it to APPROVED), a wallet order reads
     *    APPROVED once the buyer approves it, and PayPal refuses a CREATED wallet order itself. A
     *    card order still waiting on its challenge (PAYER_ACTION_REQUIRED) has no 3-D Secure
     *    result yet, and must not be captured on the strength of a read taken before the
     *    challenge failed.
     *  - A card payment is captured only on a card order createOrder() made, which carries the
     *    configured 3-D Secure request. A card paid on the wallet order (an outdated published
     *    view, or a script that hands it to the card fields) never asked for 3-D Secure.
     *  - Following PayPal's recommended actions, a card order is refused when the authentication
     *    system was unavailable (liability shift UNKNOWN) or when the card is enrolled but the
     *    cardholder was not authenticated (NO with enrollment Y). NO with any other enrollment
     *    status (not enrolled, unavailable at lookup, bypassed), a POSSIBLE shift, or no
     *    authentication at all (3-D Secure not required under SCA_WHEN_REQUIRED) proceeds with
     *    liability where PayPal leaves it.
     *
     * The JS SDK's liabilityShift cannot make these calls: it lacks the enrollment status, and a
     * browser can skip it by calling the capture route directly. A refused order stays
     * un-captured and payment_id still points at it, so a retry is refused the same way; the card
     * fields mint a fresh order on the next submit.
     */
    protected function refuseUncapturableOrder(Reservation $reservation, string $orderId, Order $order): ?JsonResponse
    {
        $card = $order->getPaymentSource()?->getCard();
        $liabilityShift = $card?->getAuthenticationResult()?->getLiabilityShift();
        $threeDSecure = $card?->getAuthenticationResult()?->getThreeDSecure();

        $payable = in_array($order->getStatus(), ['CREATED', 'APPROVED'], true);

        $reason = match (true) {
            ! $payable => 'the order is not ready to capture',
            // A PayPal wallet payment: PayPal authenticates the payer itself.
            $card === null => null,
            ! PaypalPaymentGateway::isCardOrder($order) => 'a card paid an order that requested no 3-D Secure',
            $liabilityShift === 'UNKNOWN',
            $liabilityShift === 'NO' && $threeDSecure?->getEnrollmentStatus() === 'Y' => '3-D Secure authentication did not succeed',
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        Log::warning("PayPal: Capture refused — {$reason}", [
            'order_id' => $orderId,
            'reservation_id' => $reservation->id,
            'order_status' => $order->getStatus(),
            'liability_shift' => $liabilityShift,
            'enrollment_status' => $threeDSecure?->getEnrollmentStatus(),
            'authentication_status' => $threeDSecure?->getAuthenticationStatus(),
        ]);

        if (! $payable) {
            // What PayPal itself answers for capturing an order that is not ready (422).
            return $this->declined();
        }

        return response()->json([
            'error' => 'Card authentication failed',
            'message' => 'Your bank could not verify this card. Please try again or use a different payment method.',
        ], 400);
    }

    protected function respondWithCapturedOrder(Reservation $reservation, string $orderId): JsonResponse
    {
        try {
            $order = $this->paypalResult($this->client()->getOrdersController()->getOrder(['id' => $orderId]));
        } catch (\Throwable $exception) {
            Log::error('PayPal: Could not read an already-captured order', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->captureFailed();
        }

        return $this->respondToOrder($reservation, $orderId, $order);
    }

    /**
     * Respond from the order PayPal returned for the capture. The ORDER-level COMPLETED status is
     * not the payment outcome: PayPal can return a COMPLETED order whose capture is DECLINED (ACDC
     * processor decline) or PENDING (eCheck / risk review), so the capture's own status decides.
     */
    protected function respondToOrder(Reservation $reservation, string $orderId, $order): JsonResponse
    {
        $status = $order?->getStatus();
        $capture = ($order?->getPurchaseUnits()[0] ?? null)?->getPayments()?->getCaptures()[0] ?? null;
        $captureId = $capture?->getId();
        $captureStatus = $capture?->getStatus();

        Log::info('PayPal: Capture response', [
            'order_id' => $orderId,
            'reservation_id' => $reservation->id,
            'status' => $status,
            'capture_id' => $captureId,
            'capture_status' => $captureStatus,
        ]);

        if ($status !== 'COMPLETED' || ! $captureId) {
            Log::warning('PayPal: Capture did not complete as expected', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'status' => $status,
            ]);

            return $this->captureFailed(400);
        }

        // Stored for every capture outcome — the capture id is what every later actor keys on: the
        // webhook confirms/denies by it, and retrievePaymentIntent() maps it to processing
        // (PENDING) or canceled (DECLINED/FAILED) so the pay-by-link page resumes correctly.
        $reservation->update(['payment_id' => $captureId]);

        if ($captureStatus === 'COMPLETED' || $captureStatus === 'PENDING') {
            // PENDING: money is settling, not failed. The PAYMENT.CAPTURE.COMPLETED / DENIED
            // webhook resolves the outcome; the JS shows the pending surface meanwhile.
            return response()->json([
                'status' => $captureStatus,
                'captureId' => $captureId,
                'reservationId' => $reservation->id,
            ]);
        }

        // DECLINED/FAILED: terminal for this capture, no money moved. Unlike a 422 decline (where
        // the order stays capturable and the customer can simply retry), the order here is
        // consumed — and payment_id now references the dead capture — so the JS must obtain a
        // fresh order before the next attempt.
        Log::warning('PayPal: Capture declined inside a completed order', [
            'order_id' => $orderId,
            'reservation_id' => $reservation->id,
            'capture_id' => $captureId,
            'capture_status' => $captureStatus,
        ]);

        return $this->declined(['requiresNewOrder' => true]);
    }

    protected function declined(array $extra = []): JsonResponse
    {
        return response()->json([
            'error' => 'Payment declined',
            'message' => 'PayPal has declined this payment. Please try a different payment method.',
            ...$extra,
        ], 400);
    }

    protected function captureFailed(int $status = 502): JsonResponse
    {
        return response()->json([
            'error' => 'Capture failed',
            'message' => 'PayPal could not process the payment. Please try again.',
        ], $status);
    }
}
