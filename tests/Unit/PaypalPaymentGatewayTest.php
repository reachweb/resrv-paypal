<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Illuminate\Http\Request;
use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\TestCase;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Models\Reservation;

class PaypalPaymentGatewayTest extends TestCase
{
    protected PaypalPaymentGateway $gateway;

    protected $mockClient;

    protected $mockOrdersController;

    protected $mockPaymentsController;

    protected $mockWebhookVerifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(PaypalServerSdkClient::class);
        $this->mockOrdersController = Mockery::mock(OrdersController::class);
        $this->mockPaymentsController = Mockery::mock(PaymentsController::class);
        $this->mockWebhookVerifier = Mockery::mock(WebhookSignatureVerifier::class);

        $this->mockClient->shouldReceive('getOrdersController')
            ->andReturn($this->mockOrdersController);
        $this->mockClient->shouldReceive('getPaymentsController')
            ->andReturn($this->mockPaymentsController);

        $this->app->instance(PaypalServerSdkClient::class, $this->mockClient);
        $this->app->instance(WebhookSignatureVerifier::class, $this->mockWebhookVerifier);

        $this->gateway = new PaypalPaymentGateway($this->mockWebhookVerifier);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_public_key(): void
    {
        $reservation = Mockery::mock(Reservation::class);

        $result = $this->gateway->getPublicKey($reservation);

        $this->assertEquals('test_client_id', $result);
    }

    #[Test]
    public function it_returns_secret_key(): void
    {
        $reservation = Mockery::mock(Reservation::class);

        $result = $this->gateway->getSecretKey($reservation);

        $this->assertEquals('test_client_secret', $result);
    }

    #[Test]
    public function it_returns_webhook_secret(): void
    {
        $reservation = Mockery::mock(Reservation::class);

        $result = $this->gateway->getWebhookSecret($reservation);

        $this->assertEquals('test_webhook_id', $result);
    }

    #[Test]
    public function it_supports_webhooks(): void
    {
        $this->assertTrue($this->gateway->supportsWebhooks());
    }

    #[Test]
    public function it_does_not_redirect_for_payment(): void
    {
        // JS SDK flow does not redirect - payment is handled inline
        $this->assertFalse($this->gateway->redirectsForPayment());
    }

    #[Test]
    public function it_does_not_handle_payment_pending(): void
    {
        $this->assertFalse($this->gateway->handlePaymentPending());
    }

    #[Test]
    public function it_verifies_webhook_returns_true(): void
    {
        $this->assertTrue($this->gateway->verifyWebhook());
    }

    #[Test]
    public function it_creates_payment_intent_with_correct_sdk_method(): void
    {
        $payment = Mockery::mock();
        $payment->shouldReceive('format')->andReturn('100.00');

        // Create an entry mock with a title property
        $entry = new \stdClass;
        $entry->title = 'Test Reservation';

        // Mock the Reservation class with shouldIgnoreMissing to handle Eloquent's setAttribute
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->shouldReceive('entry')->andReturn($entry);
        $reservation->id = 123;

        // Re-bind the mock client
        $this->app->instance(PaypalServerSdkClient::class, $this->mockClient);

        // Mock the order response (JS SDK flow - no links needed)
        $orderResult = Mockery::mock();
        $orderResult->shouldReceive('getId')->andReturn('ORDER-123');

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($orderResult);

        // Verify the correct method name is called
        $this->mockOrdersController->shouldReceive('createOrder')
            ->once()
            ->with(Mockery::on(function ($args) {
                return isset($args['body']);
            }))
            ->andReturn($response);

        $result = $this->gateway->paymentIntent($payment, $reservation, []);

        // JS SDK flow: id and client_secret both contain the order ID
        $this->assertEquals('ORDER-123', $result->id);
        $this->assertEquals('ORDER-123', $result->client_secret);
    }

    #[Test]
    public function it_refunds_payment_with_correct_sdk_method(): void
    {
        $payment = Mockery::mock();
        $payment->shouldReceive('format')->andReturn('100.00');

        // Mock the Reservation class with shouldIgnoreMissing to handle Eloquent's setAttribute
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('payment_id')->andReturn('CAPTURE-123');
        $reservation->shouldReceive('getAttribute')->with('payment')->andReturn($payment);
        $reservation->payment_id = 'CAPTURE-123';
        $reservation->payment = $payment;

        $refundResult = Mockery::mock();
        $refundResult->shouldReceive('getId')->andReturn('REFUND-123');

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($refundResult);

        // Verify the correct method name is called
        $this->mockPaymentsController->shouldReceive('refundCapturedPayment')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['captureId'] === 'CAPTURE-123' && isset($args['body']);
            }))
            ->andReturn($response);

        $result = $this->gateway->refund($reservation);

        $this->assertEquals('REFUND-123', $result->getId());
    }

    #[Test]
    public function it_throws_refund_failed_exception_on_error(): void
    {
        $payment = Mockery::mock();
        $payment->shouldReceive('format')->andReturn('100.00');

        // Mock the Reservation class with shouldIgnoreMissing to handle Eloquent's setAttribute
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('payment_id')->andReturn('CAPTURE-123');
        $reservation->shouldReceive('getAttribute')->with('payment')->andReturn($payment);
        $reservation->payment_id = 'CAPTURE-123';
        $reservation->payment = $payment;

        $this->mockPaymentsController->shouldReceive('refundCapturedPayment')
            ->once()
            ->andThrow(new \Exception('PayPal API error'));

        $this->expectException(RefundFailedException::class);

        $this->gateway->refund($reservation);
    }

    #[Test]
    public function it_ignores_irrelevant_webhook_events(): void
    {
        // Mock the verifier to return true (valid signature)
        $this->mockWebhookVerifier
            ->shouldReceive('verify')
            ->once()
            ->andReturn(true);

        $request = Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event_type' => 'SOME.OTHER.EVENT',
                'resource' => ['id' => 'test_id'],
            ])
        );

        $response = $this->gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function it_rejects_webhooks_with_invalid_signature(): void
    {
        // Mock the verifier to return false (invalid signature)
        $this->mockWebhookVerifier
            ->shouldReceive('verify')
            ->once()
            ->andReturn(false);

        $request = Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'resource' => ['id' => 'test_id'],
            ])
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->gateway->verifyPayment($request);
    }

    #[Test]
    public function it_rejects_webhooks_when_signature_verification_throws(): void
    {
        // Mock the verifier to throw an exception
        $this->mockWebhookVerifier
            ->shouldReceive('verify')
            ->once()
            ->andThrow(new \RuntimeException('PayPal webhook ID is not configured.'));

        $request = Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
                'resource' => ['id' => 'test_id'],
            ])
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->gateway->verifyPayment($request);
    }
}
