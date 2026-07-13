<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Illuminate\Http\Request;
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

    /**
     * Mock a captureOrder call for ORDER-123 returning a COMPLETED order whose single capture is
     * CAPTURE-456 with the given capture-level status — the status the controller must branch on.
     */
    protected function mockCaptureOrderResponse(string $captureStatus): void
    {
        $capture = Mockery::mock();
        $capture->shouldReceive('getId')->andReturn('CAPTURE-456');
        $capture->shouldReceive('getStatus')->andReturn($captureStatus);

        $payments = Mockery::mock();
        $payments->shouldReceive('getCaptures')->andReturn([$capture]);

        $purchaseUnit = Mockery::mock();
        $purchaseUnit->shouldReceive('getPayments')->andReturn($payments);

        $result = Mockery::mock();
        $result->shouldReceive('getStatus')->andReturn('COMPLETED');
        $result->shouldReceive('getPurchaseUnits')->andReturn([$purchaseUnit]);

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($result);
        $response->shouldReceive('getStatusCode')->andReturn(201);

        $this->mockOrdersController->shouldReceive('captureOrder')
            ->with(['id' => 'ORDER-123'])
            ->andReturn($response);
    }

    /**
     * Build a capture request whose session owns $sessionReservationId (the value Resrv stores under
     * 'resrv_reservation' for the in-flight checkout). Pass null to simulate no owning session.
     */
    protected function captureRequest(?int $sessionReservationId): Request
    {
        $request = Request::create('/resrv-paypal/capture/ORDER-123', 'POST');

        $session = $this->app['session']->driver();
        if ($sessionReservationId !== null) {
            $session->put('resrv_reservation', $sessionReservationId);
        }
        $request->setLaravelSession($session);

        return $request;
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

        $response = $controller->capture($this->captureRequest(123), 'INVALID-ORDER');

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(['error' => 'Invalid order'], $response->getData(true));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_returns_403_when_session_does_not_own_the_reservation(): void
    {
        // A leaked/guessed order ID for a normal (PENDING) checkout captured from a different (or
        // no) session must be rejected.
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';
        $reservationInstance->hold_expires_at = null;
        // A normal checkout reservation is PENDING, never AWAITING_PAYMENT, so the pay-by-link
        // bypass does not apply — the session guard is the only authorisation.
        $reservationInstance->shouldReceive('isAwaitingPayment')->andReturn(false);
        // Must never reach the capture call.
        $this->mockOrdersController->shouldNotReceive('captureOrder');

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        $controller = new PaypalCaptureController;

        $response = $controller->capture($this->captureRequest(999), 'ORDER-123');

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(['error' => 'Invalid order'], $response->getData(true));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_captures_a_pay_by_link_manual_reservation_without_an_owning_session(): void
    {
        // Admin-created (manual) reservations are created viaCp, so AddReservationIdToSession never
        // seeds 'resrv_reservation', and the customer pays through an HMAC deep link in a fresh
        // browser. The capture must still succeed on the strength of the unguessable order ID.
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';
        // An unexpired hold must not block the capture.
        $reservationInstance->hold_expires_at = now()->addHour();
        $reservationInstance->shouldReceive('isAwaitingPayment')->andReturn(true);
        $reservationInstance->shouldReceive('update')
            ->with(['payment_id' => 'CAPTURE-456'])
            ->andReturn(true);

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        $this->mockCaptureOrderResponse('COMPLETED');

        $controller = new PaypalCaptureController;

        // No owning session — the manual-reservation bypass authorises the capture.
        $result = $controller->capture($this->captureRequest(null), 'ORDER-123');

        $this->assertEquals(200, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('COMPLETED', $data['status']);
        $this->assertEquals('CAPTURE-456', $data['captureId']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_rejects_a_pay_by_link_capture_after_the_hold_deadline(): void
    {
        // The pay page refuses payment past hold_expires_at on its own because the lapsed-hold
        // sweep can lag. A customer who opened the PayPal popup before the deadline must not be
        // able to capture after it while the reservation is still (stale) AWAITING_PAYMENT.
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';
        $reservationInstance->hold_expires_at = now()->subMinute();
        $reservationInstance->shouldReceive('isAwaitingPayment')->andReturn(true);
        // Must never reach the capture call.
        $this->mockOrdersController->shouldNotReceive('captureOrder');

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        $controller = new PaypalCaptureController;

        $response = $controller->capture($this->captureRequest(null), 'ORDER-123');

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

        $this->mockCaptureOrderResponse('COMPLETED');

        $controller = new PaypalCaptureController;

        $result = $controller->capture($this->captureRequest(123), 'ORDER-123');

        $this->assertEquals(200, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('COMPLETED', $data['status']);
        $this->assertEquals('CAPTURE-456', $data['captureId']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_reports_a_pending_capture_as_pending_and_stores_the_capture_id(): void
    {
        // eCheck / risk review: PayPal returns a COMPLETED order whose capture is PENDING — money
        // settling, not paid. The JS must get PENDING (pending surface, not success), and the
        // capture id must be stored so the resolving webhook and any resume key on it.
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';
        $reservationInstance->shouldReceive('update')
            ->once()
            ->with(['payment_id' => 'CAPTURE-456'])
            ->andReturn(true);

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        $this->mockCaptureOrderResponse('PENDING');

        $controller = new PaypalCaptureController;

        $result = $controller->capture($this->captureRequest(123), 'ORDER-123');

        $this->assertEquals(200, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('PENDING', $data['status']);
        $this->assertEquals('CAPTURE-456', $data['captureId']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_reports_a_declined_capture_inside_a_completed_order_as_declined(): void
    {
        // ACDC processor declines surface as a COMPLETED order carrying a DECLINED capture — the
        // order status alone must not read as success. The capture id is still stored so
        // retrievePaymentIntent() maps the dead order to canceled and the customer can retry on a
        // fresh one.
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $reservationInstance = Mockery::mock();
        $reservationInstance->id = 123;
        $reservationInstance->payment_id = 'ORDER-123';
        $reservationInstance->shouldReceive('update')
            ->once()
            ->with(['payment_id' => 'CAPTURE-456'])
            ->andReturn(true);

        $this->mockReservation->shouldReceive('findByPaymentId')
            ->with('ORDER-123')
            ->andReturnSelf();
        $this->mockReservation->shouldReceive('first')
            ->andReturn($reservationInstance);

        $this->mockCaptureOrderResponse('DECLINED');

        $controller = new PaypalCaptureController;

        $result = $controller->capture($this->captureRequest(123), 'ORDER-123');

        $this->assertEquals(400, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('Payment declined', $data['error']);
        // The order is consumed (and payment_id now references the dead capture), so the JS
        // must fetch a fresh order before the customer retries.
        $this->assertTrue($data['requiresNewOrder']);
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
        $response->shouldReceive('getStatusCode')->andReturn(201);
        $response->shouldReceive('getBody')->andReturn('{"status":"PENDING"}');

        $this->mockOrdersController->shouldReceive('captureOrder')
            ->with(['id' => 'ORDER-123'])
            ->andReturn($response);

        $controller = new PaypalCaptureController;

        $result = $controller->capture($this->captureRequest(123), 'ORDER-123');

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

        $result = $controller->capture($this->captureRequest(123), 'ORDER-123');

        $this->assertEquals(500, $result->getStatusCode());
        $data = $result->getData(true);
        $this->assertEquals('Capture failed', $data['error']);
        $this->assertEquals('PayPal API error', $data['message']);
    }
}
