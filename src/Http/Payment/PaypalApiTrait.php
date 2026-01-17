<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Support\Facades\Http;

trait PaypalApiTrait
{
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
}
