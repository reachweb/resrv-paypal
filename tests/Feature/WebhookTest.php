<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Illuminate\Http\Request;
use Mockery;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\TestCase;

class WebhookTest extends TestCase
{
    protected $mockWebhookVerifier;

    protected function setUp(): void
    {
        parent::setUp();

        $mockClient = Mockery::mock(PaypalServerSdkClient::class);
        $this->app->instance(PaypalServerSdkClient::class, $mockClient);

        $this->mockWebhookVerifier = Mockery::mock(WebhookSignatureVerifier::class);
        $this->app->instance(WebhookSignatureVerifier::class, $this->mockWebhookVerifier);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_ignores_unknown_webhook_events(): void
    {
        $this->mockWebhookVerifier
            ->shouldReceive('verify')
            ->once()
            ->andReturn(true);

        $gateway = new PaypalPaymentGateway($this->mockWebhookVerifier);

        $request = Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event_type' => 'UNKNOWN.EVENT.TYPE',
                'resource' => ['id' => 'test_id'],
            ])
        );

        $response = $gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_true_for_verify_webhook(): void
    {
        $gateway = new PaypalPaymentGateway($this->mockWebhookVerifier);

        $this->assertTrue($gateway->verifyWebhook());
    }

    #[Test]
    public function it_rejects_invalid_json_payload(): void
    {
        $gateway = new PaypalPaymentGateway($this->mockWebhookVerifier);

        $request = Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'invalid json'
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $gateway->verifyPayment($request);
    }

    #[Test]
    public function it_returns_200_for_events_without_capture_id(): void
    {
        $this->mockWebhookVerifier
            ->shouldReceive('verify')
            ->once()
            ->andReturn(true);

        $gateway = new PaypalPaymentGateway($this->mockWebhookVerifier);

        $request = Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'resource' => [],
            ])
        );

        $response = $gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_webhooks_without_valid_signature(): void
    {
        $this->mockWebhookVerifier
            ->shouldReceive('verify')
            ->once()
            ->andReturn(false);

        $gateway = new PaypalPaymentGateway($this->mockWebhookVerifier);

        $request = Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'resource' => ['id' => 'test_capture_id'],
            ])
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $gateway->verifyPayment($request);
    }
}
