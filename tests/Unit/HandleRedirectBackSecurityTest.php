<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Illuminate\Http\Request;
use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\TestCase;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Tests for handleRedirectBack() in the JS SDK flow.
 *
 * The capture is performed by the frontend calling the capture endpoint, which stores the capture
 * ID on the reservation. handleRedirectBack() runs when the customer lands on the checkout-complete
 * page and re-verifies the capture status with PayPal (mirroring the Stripe gateway, which re-reads
 * the PaymentIntent) rather than trusting that payment_id is merely present.
 */
class HandleRedirectBackSecurityTest extends TestCase
{
    protected PaypalPaymentGateway $gateway;

    protected $mockClient;

    protected $mockOrdersController;

    protected $mockPaymentsController;

    protected $mockWebhookVerifier;

    protected $mockReservation;

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
    #[RunInSeparateProcess]
    public function it_returns_success_when_capture_is_completed(): void
    {
        $this->setupMockReservation(123, 'CAPTURE-123');
        $this->mockCaptureStatus('COMPLETED');
        $this->simulateRequest(['id' => 123]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertTrue($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
        $this->assertEquals('CAPTURE-123', $result['reservation']['payment_id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_failure_when_capture_is_not_completed(): void
    {
        $this->setupMockReservation(123, 'CAPTURE-123');
        $this->mockCaptureStatus('PENDING');
        $this->simulateRequest(['id' => 123]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_failure_when_payment_id_not_set(): void
    {
        // No capture happened yet, so payment_id is empty and PayPal is never queried.
        $this->setupMockReservation(123, null);
        $this->mockPaymentsController->shouldNotReceive('getCapturedPayment');
        $this->simulateRequest(['id' => 123]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_handles_cancelled_payment(): void
    {
        // A cancelled payment short-circuits before any capture lookup.
        $this->setupMockReservation(123, null);
        $this->mockPaymentsController->shouldNotReceive('getCapturedPayment');
        $this->simulateRequest(['id' => 123, 'cancelled' => 'true']);

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_graceful_failure_when_reservation_not_found(): void
    {
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $this->mockReservation->shouldReceive('find')->andReturnNull();
        $this->mockPaymentsController->shouldNotReceive('getCapturedPayment');
        $this->simulateRequest(['id' => 999]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals([], $result['reservation']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_reservation_data_on_success(): void
    {
        $this->setupMockReservation(456, 'CAPTURE-456');
        $this->mockCaptureStatus('COMPLETED');
        $this->simulateRequest(['id' => 456]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertTrue($result['status']);
        $this->assertArrayHasKey('reservation', $result);
        $this->assertEquals(456, $result['reservation']['id']);
    }

    protected function setupMockReservation(int $id, ?string $paymentId, string $status = 'pending'): void
    {
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $this->mockReservation->id = $id;
        $this->mockReservation->payment_id = $paymentId;
        $this->mockReservation->status = $status;

        $this->mockReservation->shouldReceive('find')
            ->with($id)
            ->andReturnSelf();

        $this->mockReservation->shouldReceive('toArray')
            ->andReturnUsing(function () use ($id) {
                return [
                    'id' => $id,
                    'payment_id' => $this->mockReservation->payment_id,
                    'status' => $this->mockReservation->status,
                ];
            });
    }

    protected function mockCaptureStatus(string $status): void
    {
        $result = Mockery::mock();
        $result->shouldReceive('getStatus')->andReturn($status);

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($result);

        $this->mockPaymentsController->shouldReceive('getCapturedPayment')
            ->andReturn($response);
    }

    protected function simulateRequest(array $params): void
    {
        $request = Request::create('/', 'GET', $params);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $this->app->instance('request', $request);
    }
}
