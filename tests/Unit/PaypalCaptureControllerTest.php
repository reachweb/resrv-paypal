<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Controllers\PaypalCaptureController;
use Reach\ResrvPaymentPaypal\Tests\TestCase;
use Reach\StatamicResrv\Models\Reservation;

class PaypalCaptureControllerTest extends TestCase
{
    protected $mockClient;

    protected $mockOrdersController;

    protected $mockReservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(PaypalServerSdkClient::class);
        $this->mockOrdersController = Mockery::mock(OrdersController::class);

        $this->mockClient->shouldReceive('getOrdersController')
            ->andReturn($this->mockOrdersController);

        $this->app->instance(PaypalServerSdkClient::class, $this->mockClient);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_403_for_invalid_order_id(): void
    {
        // Mock Reservation::findByPaymentId to return empty collection
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('INVALID-ORDER')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturnNull();

        $controller = new PaypalCaptureController;
        $request = new \Illuminate\Http\Request;

        $response = $controller->capture($request, 'INVALID-ORDER');

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(['error' => 'Invalid order'], $response->getData(true));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_captures_order_successfully(): void
    {
        // Setup mock reservation
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';
        $reservationInstance->shouldReceive('update')
            ->with(['payment_id' => 'CAPTURE-456'])
            ->andReturn(true);

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        // Mock PayPal capture response
        $capture = Mockery::mock();
        $capture->shouldReceive('getId')->andReturn('CAPTURE-456');

        $payments = Mockery::mock();
        $payments->shouldReceive('getCaptures')->andReturn([$capture]);

        $purchaseUnit = Mockery::mock();
        $purchaseUnit->shouldReceive('getPayments')->andReturn($payments);

        $result = Mockery::mock();
        $result->shouldReceive('getStatus')->andReturn('COMPLETED');
        $result->shouldReceive('getPurchaseUnits')->andReturn([$purchaseUnit]);

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($result);

        $this->mockOrdersController->shouldReceive('captureOrder')
            ->with(['id' => 'ORDER-123'])
            ->andReturn($response);

        $controller = new PaypalCaptureController;
        $request = new \Illuminate\Http\Request;

        $result = $controller->capture($request, 'ORDER-123');

        $this->assertEquals(200, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('COMPLETED', $data['status']);
        $this->assertEquals('CAPTURE-456', $data['captureId']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_400_when_capture_fails(): void
    {
        // Setup mock reservation
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        // Mock PayPal capture response with non-COMPLETED status
        $result = Mockery::mock();
        $result->shouldReceive('getStatus')->andReturn('PENDING');

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($result);

        $this->mockOrdersController->shouldReceive('captureOrder')
            ->with(['id' => 'ORDER-123'])
            ->andReturn($response);

        $controller = new PaypalCaptureController;
        $request = new \Illuminate\Http\Request;

        $result = $controller->capture($request, 'ORDER-123');

        $this->assertEquals(400, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('Capture failed', $data['error']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_500_on_api_exception(): void
    {
        // Setup mock reservation
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        // Mock PayPal API exception
        $this->mockOrdersController->shouldReceive('captureOrder')
            ->with(['id' => 'ORDER-123'])
            ->andThrow(new \Exception('PayPal API error'));

        $controller = new PaypalCaptureController;
        $request = new \Illuminate\Http\Request;

        $result = $controller->capture($request, 'ORDER-123');

        $this->assertEquals(500, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('Capture failed', $data['error']);
        $this->assertEquals('PayPal API error', $data['message']);
    }
}
