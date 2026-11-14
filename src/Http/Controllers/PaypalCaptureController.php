<?php

namespace Reach\ResrvPaymentPaypal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\PaypalServerSdkClient;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalApiException;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalApiTrait;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Models\Reservation;

class PaypalCaptureController extends Controller
{
    use PaypalApiTrait;

    protected PaypalServerSdkClient $client;

    public function __construct()
    {
        $this->client = app(PaypalServerSdkClient::class);
    }

    public function capture(Request $request, string $orderId): JsonResponse
    {
        Log::info('PayPal: Capture request received', [
            'order_id' => $orderId,
            'ip' => $request->ip(),
        ]);

        // Security: the order ID is an unguessable capability token bound to exactly one
        // reservation by findByPaymentId. It authorises capture in either legitimate context:
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

        if (! $reservation) {
            Log::warning('PayPal: Capture attempted for unknown order', [
                'order_id' => $orderId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid order'], 403);
        }

        $sessionOwnsReservation = (string) $request->session()->get('resrv_reservation') === (string) $reservation->id;

        // The reservation row stays locked for the whole capture. PayPal has no void for
        // CAPTURE-intent orders, so refusing here is what voids one: a transition that changes
        // payability (admin marks paid or cancels, the lapsed-hold sweep, expiry, a checkout that
        // replaced the intent) either commits first — and this capture is refused — or waits for
        // it and then sees the stored capture id as money already moving. Without the lock a
        // capture could land between Resrv verifying the "void" and its commit, unnoticed.
        // The SDK client's 15s timeout bounds how long the lock is held, as for refunds.
        return DB::transaction(function () use ($request, $reservation, $orderId, $sessionOwnsReservation) {
            $fresh = Reservation::query()->lockForUpdate()->find($reservation->id);

            if (! $fresh || $fresh->payment_id !== $orderId || ! $this->mayCapture($fresh, $sessionOwnsReservation)) {
                Log::warning('PayPal: Capture refused — order no longer payable for this request', [
                    'order_id' => $orderId,
                    'reservation_id' => $reservation->id,
                    'status' => $fresh?->status,
                    'session_owns_reservation' => $sessionOwnsReservation,
                    'hold_expires_at' => $fresh?->hold_expires_at?->toIso8601String(),
                    'ip' => $request->ip(),
                ]);

                return response()->json(['error' => 'Invalid order'], 403);
            }

            return $this->captureLocked($fresh, $orderId);
        });
    }

    /**
     * The same rule PaypalPaymentGateway::orderCanStillBeCaptured() reports to Resrv, plus the
     * per-request binding: a PENDING checkout only from its own session, an awaiting-payment
     * reservation only before its hold deadline.
     */
    protected function mayCapture(Reservation $reservation, bool $sessionOwnsReservation): bool
    {
        return match ($reservation->status) {
            ReservationStatus::PENDING->value => $sessionOwnsReservation,
            ReservationStatus::AWAITING_PAYMENT->value => ! $reservation->holdDeadlinePassed(),
            default => false,
        };
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
            $order = $this->paypalResult($this->client->getOrdersController()->captureOrder([
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
            // Transport failure (timeout, connection). PayPal may still have captured: a retry
            // lands on ORDER_ALREADY_CAPTURED above, and the webhook confirms either way.
            Log::error('PayPal: Capture API error', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->captureFailed();
        }

        return $this->respondToOrder($reservation, $orderId, $order);
    }

    protected function respondWithCapturedOrder(Reservation $reservation, string $orderId): JsonResponse
    {
        try {
            $order = $this->paypalResult($this->client->getOrdersController()->getOrder(['id' => $orderId]));
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
