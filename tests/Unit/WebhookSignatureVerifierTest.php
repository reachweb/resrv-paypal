<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\TestCase;

class WebhookSignatureVerifierTest extends TestCase
{
    protected WebhookSignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new WebhookSignatureVerifier;
    }

    #[Test]
    public function it_throws_exception_when_webhook_id_not_configured(): void
    {
        config(['services.paypal.webhook_id' => null]);

        $request = $this->createWebhookRequest();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayPal webhook ID is not configured');

        $this->verifier->verify($request, '{}');
    }

    #[Test]
    public function it_returns_false_when_auth_algo_header_missing(): void
    {
        $request = $this->createWebhookRequest([
            'PAYPAL-AUTH-ALGO' => null,
        ]);

        $result = $this->verifier->verify($request, '{}');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_when_cert_url_header_missing(): void
    {
        $request = $this->createWebhookRequest([
            'PAYPAL-CERT-URL' => null,
        ]);

        $result = $this->verifier->verify($request, '{}');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_when_transmission_id_header_missing(): void
    {
        $request = $this->createWebhookRequest([
            'PAYPAL-TRANSMISSION-ID' => null,
        ]);

        $result = $this->verifier->verify($request, '{}');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_when_transmission_sig_header_missing(): void
    {
        $request = $this->createWebhookRequest([
            'PAYPAL-TRANSMISSION-SIG' => null,
        ]);

        $result = $this->verifier->verify($request, '{}');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_when_transmission_time_header_missing(): void
    {
        $request = $this->createWebhookRequest([
            'PAYPAL-TRANSMISSION-TIME' => null,
        ]);

        $result = $this->verifier->verify($request, '{}');

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_when_paypal_returns_success(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test_token'], 200),
            '*/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $request = $this->createWebhookRequest();
        $payload = json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED']);

        $result = $this->verifier->verify($request, $payload);

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_paypal_returns_failure(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test_token'], 200),
            '*/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'FAILURE',
            ], 200),
        ]);

        $request = $this->createWebhookRequest();
        $payload = json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED']);

        $result = $this->verifier->verify($request, $payload);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_false_when_verification_api_fails(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test_token'], 200),
            '*/v1/notifications/verify-webhook-signature' => Http::response('Internal Server Error', 500),
        ]);

        $request = $this->createWebhookRequest();
        $payload = json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED']);

        $result = $this->verifier->verify($request, $payload);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_throws_exception_when_token_request_fails(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response('Unauthorized', 401),
        ]);

        $request = $this->createWebhookRequest();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to obtain PayPal access token');

        $this->verifier->verify($request, '{}');
    }

    #[Test]
    public function it_sends_correct_headers_to_paypal(): void
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test_token'], 200),
            '*/v1/notifications/verify-webhook-signature' => Http::response([
                'verification_status' => 'SUCCESS',
            ], 200),
        ]);

        $request = $this->createWebhookRequest([
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.paypal.com/cert.pem',
            'PAYPAL-TRANSMISSION-ID' => 'abc123',
            'PAYPAL-TRANSMISSION-SIG' => 'sig456',
            'PAYPAL-TRANSMISSION-TIME' => '2026-01-17T12:00:00Z',
        ]);

        $payload = json_encode(['event_type' => 'PAYMENT.CAPTURE.COMPLETED']);

        $this->verifier->verify($request, $payload);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'verify-webhook-signature')) {
                return true;
            }

            $body = $request->data();

            return $body['auth_algo'] === 'SHA256withRSA'
                && $body['cert_url'] === 'https://api.paypal.com/cert.pem'
                && $body['transmission_id'] === 'abc123'
                && $body['transmission_sig'] === 'sig456'
                && $body['transmission_time'] === '2026-01-17T12:00:00Z'
                && $body['webhook_id'] === 'test_webhook_id';
        });
    }

    #[Test]
    public function it_uses_sandbox_url_in_sandbox_mode(): void
    {
        config(['services.paypal.mode' => 'sandbox']);

        Http::fake([
            'https://api-m.sandbox.paypal.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $request = $this->createWebhookRequest();

        try {
            $this->verifier->verify($request, '{}');
        } catch (\Exception $e) {
            // Expected to fail after token request, but we just want to verify the URL
        }

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api-m.sandbox.paypal.com');
        });
    }

    #[Test]
    public function it_uses_live_url_in_live_mode(): void
    {
        config(['services.paypal.mode' => 'live']);

        Http::fake([
            'https://api-m.paypal.com/*' => Http::response(['access_token' => 'test_token'], 200),
        ]);

        $request = $this->createWebhookRequest();

        try {
            $this->verifier->verify($request, '{}');
        } catch (\Exception $e) {
            // Expected to fail after token request, but we just want to verify the URL
        }

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api-m.paypal.com')
                && ! str_contains($request->url(), 'sandbox');
        });
    }

    protected function createWebhookRequest(array $headerOverrides = []): Request
    {
        $defaultHeaders = [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-123',
            'PAYPAL-TRANSMISSION-ID' => 'test-transmission-id',
            'PAYPAL-TRANSMISSION-SIG' => 'test-signature',
            'PAYPAL-TRANSMISSION-TIME' => '2026-01-17T12:00:00Z',
        ];

        $headers = array_merge($defaultHeaders, $headerOverrides);

        $request = Request::create('/', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        foreach ($headers as $key => $value) {
            if ($value !== null) {
                $request->headers->set($key, $value);
            }
        }

        return $request;
    }
}
