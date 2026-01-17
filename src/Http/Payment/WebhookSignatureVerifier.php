<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookSignatureVerifier
{
    use PaypalApiTrait;

    public function verify(Request $request, string $payload): bool
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
