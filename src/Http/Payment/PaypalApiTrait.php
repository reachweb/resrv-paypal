<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use Illuminate\Support\Facades\Http;
use PaypalServerSdkLib\Http\ApiResponse;

trait PaypalApiTrait
{
    /**
     * The result of an SDK call, or a PaypalApiException when PayPal answered with an HTTP error.
     * Every SDK call goes through here: the SDK returns errors instead of throwing them, so a
     * call that reads getResult() directly mistakes an error body for a success.
     *
     * @throws PaypalApiException
     */
    protected function paypalResult(ApiResponse $response): mixed
    {
        if ($response->isError()) {
            throw PaypalApiException::fromResponse($response);
        }

        return $response->getResult();
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
}
