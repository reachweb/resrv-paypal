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

        // Security: Verify orderId matches a pending reservation
        $reservation = Reservation::findByPaymentId($orderId)->first();

        if (! $reservation) {
            Log::warning('PayPal: Capture attempted for unknown order', [
                'order_id' => $orderId,
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

            $status = is_array($result) ? ($result['status'] ?? null) : $result->getStatus();

            Log::info('PayPal: Capture response', [
                'order_id' => $orderId,
                'reservation_id' => $reservation->id,
                'status' => $status,
                'http_status' => $response->getStatusCode(),
                'result_type' => is_object($result) ? get_class($result) : gettype($result),
                'raw_body' => $status === null ? $response->getBody() : null,
            ]);

            if ($status === 'COMPLETED') {
                // Extract capture ID from response
                $purchaseUnits = is_array($result)
                    ? ($result['purchase_units'] ?? [])
                    : $result->getPurchaseUnits();

                $payments = is_array($purchaseUnits[0] ?? null)
                    ? ($purchaseUnits[0]['payments'] ?? null)
                    : ($purchaseUnits[0]?->getPayments());

                $captures = is_array($payments)
                    ? ($payments['captures'] ?? [])
                    : ($payments?->getCaptures() ?? []);

                $captureId = null;
                if (! empty($captures)) {
                    $captureId = is_array($captures[0] ?? null)
                        ? ($captures[0]['id'] ?? null)
                        : $captures[0]->getId();
                }

                if ($captureId) {
                    $reservation->update(['payment_id' => $captureId]);

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
