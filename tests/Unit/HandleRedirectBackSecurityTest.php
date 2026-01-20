<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Illuminate\Http\Request;
use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\TestCase;
use Reach\StatamicResrv\Models\Reservation;

/**
 * Tests for handleRedirectBack() method in JS SDK flow.
 *
 * In the JS SDK flow, the capture is done by the frontend calling
 * the capture endpoint. handleRedirectBack() is called when the user
 * lands on the checkout-complete page and just verifies the payment
 * was captured (payment_id exists on reservation).
 */
class HandleRedirectBackSecurityTest extends TestCase
{
    protected PaypalPaymentGateway $gateway;

    protected $mockClient;

    protected $mockOrdersController;

    protected $mockWebhookVerifier;

    protected $mockReservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(PaypalServerSdkClient::class);
        $this->mockOrdersController = Mockery::mock(OrdersController::class);
        $this->mockWebhookVerifier = Mockery::mock(WebhookSignatureVerifier::class);

        $this->mockClient->shouldReceive('getOrdersController')
            ->andReturn($this->mockOrdersController);

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
    public function it_returns_success_when_payment_id_exists(): void
    {
        $this->setupMockReservation(123, 'CAPTURE-123');
        $this->simulateRequest(['id' => 123]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertTrue($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
        $this->assertEquals('CAPTURE-123', $result['reservation']['payment_id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_failure_when_payment_id_not_set(): void
    {
        $this->setupMockReservation(123, null);
        $this->simulateRequest(['id' => 123]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_handles_cancelled_payment(): void
    {
        $this->setupMockReservation(123, null);
        $this->simulateRequest(['id' => 123, 'cancelled' => 'true']);

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_reservation_data_on_success(): void
    {
        $this->setupMockReservation(456, 'CAPTURE-456');
        $this->simulateRequest(['id' => 456]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertTrue($result['status']);
        $this->assertArrayHasKey('reservation', $result);
        $this->assertEquals(456, $result['reservation']['id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_reservation_data_on_failure(): void
    {
        $this->setupMockReservation(789, null);
        $this->simulateRequest(['id' => 789]);

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertArrayHasKey('reservation', $result);
        $this->assertEquals(789, $result['reservation']['id']);
    }

    protected function setupMockReservation(int $id, ?string $paymentId): void
    {
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $this->mockReservation->id = $id;
        $this->mockReservation->payment_id = $paymentId;

        $this->mockReservation->shouldReceive('findOrFail')
            ->with($id)
            ->andReturnSelf();

        $this->mockReservation->shouldReceive('toArray')
            ->andReturnUsing(function () use ($id) {
                return [
                    'id' => $id,
                    'payment_id' => $this->mockReservation->payment_id,
                ];
            });
    }

    protected function simulateRequest(array $params): void
    {
        $request = Request::create('/', 'GET', $params);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $this->app->instance('request', $request);
    }
}
