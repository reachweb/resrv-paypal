<?php

namespace Reach\ResrvPaymentPaypal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use PaypalServerSdkLib\PaypalServerSdkClient;
use Reach\StatamicResrv\Models\Reservation;

class PaypalCaptureController extends Controller
{
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
        //  1. Normal inline checkout — the customer's session owns the in-flight reservation
        //     (Resrv stores its id under 'resrv_reservation'). Bind to it as defence-in-depth so a
        //     leaked/guessed order ID can't be captured from another browser context.
        //
        //  2. Pay-by-link for admin-created (manual) reservations — these are created viaCp, so
        //     AddReservationIdToSession deliberately never seeds the session, and the customer
        //     reaches the pay page through an HMAC deep link in a fresh browser. There is no
        //     checkout session to bind to; the order ID is the capability. Only the CP manual-
        //     reservation flow ever produces AWAITING_PAYMENT, so this never loosens the guard for
        //     a normal PENDING checkout. The bypass also mirrors the pay page's hold-deadline
        //     guard (ReservationPayment::deadlinePassed): past hold_expires_at the reservation is
        //     no longer payable even though the lapsed-hold sweep may not have cancelled it yet,
        //     so a PayPal popup opened before the deadline must not capture after it.
        $reservation = Reservation::findByPaymentId($orderId)->first();

        if (! $reservation) {
            Log::warning('PayPal: Capture attempted for unknown order', [
                'order_id' => $orderId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid order'], 403);
        }

        $sessionOwnsReservation = (string) $request->session()->get('resrv_reservation') === (string) $reservation->id;

        if (! $sessionOwnsReservation
            && (! $reservation->isAwaitingPayment() || $reservation->hold_expires_at?->isPast())) {
            Log::warning('PayPal: Capture attempted outside the owning checkout session', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'awaiting_payment' => $reservation->isAwaitingPayment(),
                'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid order'], 403);
        }

        Log::info('PayPal: Capturing order via API', [
            'order_id' => $orderId,
            'reservation_id' => $reservation->id,
        ]);

        try {
            $ordersController = $this->client->getOrdersController();
            $response = $ordersController->captureOrder(['id' => $orderId]);
            $result = $response->getResult();

            // Handle error responses (SDK returns array for 422 errors instead of throwing)
            if (is_array($result) && isset($result['name'])) {
                $errorIssue = $result['details'][0]['issue'] ?? $result['name'];
                $errorDescription = $result['details'][0]['description'] ?? $result['message'] ?? 'Unknown error';

                Log::warning('PayPal: Capture denied by PayPal', [
                    'order_id' => $orderId,
                    'reservation_id' => $reservation->id,
                    'http_status' => $response->getStatusCode(),
                    'error' => $errorIssue,
                    'description' => $errorDescription,
                    'debug_id' => $result['debug_id'] ?? null,
                ]);

                return response()->json([
                    'error' => 'Payment declined',
                    'message' => 'PayPal has declined this payment. Please try a different payment method.',
                ], 400);
            }

            $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

            Log::info('PayPal: Capture response', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'status' => $status,
                'http_status' => $response->getStatusCode(),
            ]);

            if ($status === 'COMPLETED') {
                // The ORDER-level COMPLETED status is not the payment outcome: PayPal can return
                // a COMPLETED order whose capture is DECLINED (ACDC processor decline) or PENDING
                // (eCheck / risk review), so the capture's own status decides the response.
                $purchaseUnits = is_array($result)
                    ? ($result['purchase_units'] ?? [])
                    : $result->getPurchaseUnits();

                $payments = is_array($purchaseUnits[0] ?? null)
                    ? ($purchaseUnits[0]['payments'] ?? null)
                    : ($purchaseUnits[0]?->getPayments());

                $captures = is_array($payments)
                    ? ($payments['captures'] ?? [])
                    : ($payments?->getCaptures() ?? []);

                $capture = $captures[0] ?? null;
                $captureId = is_array($capture) ? ($capture['id'] ?? null) : $capture?->getId();
                $captureStatus = is_array($capture) ? ($capture['status'] ?? null) : $capture?->getStatus();

                if ($captureId) {
                    // Stored for every capture outcome — the capture id is what every later actor
                    // keys on: the webhook confirms/denies by it, and retrievePaymentIntent() maps
                    // it to processing (PENDING) or canceled (DECLINED/FAILED) so the pay-by-link
                    // page resumes correctly instead of reading the dead order as succeeded.
                    $reservation->update(['payment_id' => $captureId]);

                    if ($captureStatus === 'COMPLETED') {
                        Log::info('PayPal: Payment captured successfully', [
                            'order_id' => $orderId,
                            'reservation_id' => $reservation->id,
                            'capture_id' => $captureId,
                        ]);

                        return response()->json([
                            'status' => 'COMPLETED',
                            'captureId' => $captureId,
                            'reservationId' => $reservation->id,
                        ]);
                    }

                    if ($captureStatus === 'PENDING') {
                        // Money is settling, not failed. The PAYMENT.CAPTURE.COMPLETED / DENIED
                        // webhook resolves the outcome; the JS shows the pending surface meanwhile.
                        Log::info('PayPal: Capture pending (eCheck / risk review)', [
                            'order_id' => $orderId,
                            'reservation_id' => $reservation->id,
                            'capture_id' => $captureId,
                        ]);

                        return response()->json([
                            'status' => 'PENDING',
                            'captureId' => $captureId,
                            'reservationId' => $reservation->id,
                        ]);
                    }

                    // DECLINED/FAILED: terminal for this capture, no money moved. Unlike the 422
                    // decline above (where the order stays capturable and the customer can simply
                    // retry), the order here is consumed — and payment_id now references the dead
                    // capture — so the JS must obtain a fresh order before the next attempt.
                    Log::warning('PayPal: Capture declined inside a completed order', [
                        'order_id' => $orderId,
                        'reservation_id' => $reservation->id,
                        'capture_id' => $captureId,
                        'capture_status' => $captureStatus,
                    ]);

                    return response()->json([
                        'error' => 'Payment declined',
                        'message' => 'PayPal has declined this payment. Please try a different payment method.',
                        'requiresNewOrder' => true,
                    ], 400);
                }

                Log::warning('PayPal: Capture completed but no capture ID found', [
                    'order_id' => $orderId,
                    'reservation_id' => $reservation->id,
                    'captures_count' => count($captures),
                    'captures_data' => $captures,
                ]);
            }

            Log::warning('PayPal: Capture did not complete as expected', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'status' => $status,
            ]);

            return response()->json([
                'error' => 'Capture failed',
                'status' => $status,
            ], 400);
        } catch (\Exception $e) {
            Log::error('PayPal: Capture API error', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Capture failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
