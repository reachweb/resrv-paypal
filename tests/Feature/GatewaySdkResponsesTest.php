<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Models\CapturedPayment;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\Models\Refund;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\BootedAddonTestCase;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Support\ReservationRefundProcessor;

/**
 * Drives the gateway through the responses paypal-server-sdk 2.x actually produces: real models on
 * success, and on an HTTP error an ApiResponse with isError() === true — the SDK never throws for
 * those, so a test that fakes errors with andThrow() cannot catch an unchecked response.
 */
class GatewaySdkResponsesTest extends BootedAddonTestCase
{
    protected $orders;

    protected $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = Mockery::mock(OrdersController::class);
        $this->payments = Mockery::mock(PaymentsController::class);

        $client = Mockery::mock(PaypalServerSdkClient::class);
        $client->shouldReceive('getOrdersController')->andReturn($this->orders);
        $client->shouldReceive('getPaymentsController')->andReturn($this->payments);
        $this->app->instance(PaypalServerSdkClient::class, $client);
    }

    protected function gateway(): PaypalPaymentGateway
    {
        return new PaypalPaymentGateway;
    }

    #[Test]
    public function a_refund_paypal_rejects_rolls_back_instead_of_marking_the_reservation_refunded(): void
    {
        $reservation = $this->reservation();
        $this->payments->shouldReceive('refundCapturedPayment')
            ->andReturn($this->paypalError(422, 'UNPROCESSABLE_ENTITY', ['REFUND_FAILED_INSUFFICIENT_FUNDS']));

        try {
            app(ReservationRefundProcessor::class)->refund($reservation);
            $this->fail('A refund PayPal rejected was reported as successful.');
        } catch (RefundFailedException) {
        }

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    #[Test]
    public function every_refund_error_status_throws_refund_failed(): void
    {
        foreach ([401 => 'AUTHENTICATION_FAILURE', 404 => 'RESOURCE_NOT_FOUND', 500 => 'INTERNAL_SERVER_ERROR'] as $status => $name) {
            $this->payments->shouldReceive('refundCapturedPayment')->once()->andReturn($this->paypalError($status, $name));

            try {
                $this->gateway()->refund($this->reservation());
                $this->fail("A PayPal {$status} refund response was treated as a refund.");
            } catch (RefundFailedException) {
            }
        }
    }

    #[Test]
    public function a_refund_paypal_reports_as_failed_throws(): void
    {
        $this->payments->shouldReceive('refundCapturedPayment')
            ->andReturn($this->paypalSuccess(Refund::class, ['id' => 'REFUND-1', 'status' => 'FAILED'], 201));

        $this->expectException(RefundFailedException::class);

        $this->gateway()->refund($this->reservation());
    }

    #[Test]
    public function an_already_fully_refunded_capture_counts_as_refunded(): void
    {
        $this->payments->shouldReceive('refundCapturedPayment')
            ->andReturn($this->paypalError(422, 'UNPROCESSABLE_ENTITY', ['CAPTURE_FULLY_REFUNDED']));

        $this->assertTrue($this->gateway()->refund($this->reservation()));
    }

    #[Test]
    public function a_completed_refund_returns_the_refund(): void
    {
        $this->payments->shouldReceive('refundCapturedPayment')
            ->andReturn($this->paypalSuccess(Refund::class, ['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201));

        $this->assertSame('REFUND-1', $this->gateway()->refund($this->reservation())->getId());
    }

    #[Test]
    public function a_stored_capture_id_resolves_through_the_capture_when_the_order_lookup_404s(): void
    {
        // After capture, payment_id holds the capture id, which the Orders API 404s.
        $reservation = $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'CAPTURE-9']);
        $this->orders->shouldReceive('getOrder')->with(['id' => 'CAPTURE-9'])
            ->andReturn($this->paypalError(404, 'RESOURCE_NOT_FOUND', ['INVALID_RESOURCE_ID']));

        $statuses = [];
        foreach (['COMPLETED' => 'succeeded', 'PENDING' => 'processing', 'DECLINED' => 'canceled'] as $captureStatus => $expected) {
            $this->payments->shouldReceive('getCapturedPayment')->once()->with(['captureId' => 'CAPTURE-9'])
                ->andReturn($this->paypalSuccess(CapturedPayment::class, ['id' => 'CAPTURE-9', 'status' => $captureStatus]));
            $statuses[$captureStatus] = $this->gateway()->retrievePaymentIntent('CAPTURE-9', $reservation)?->status;
        }

        $this->assertSame(['COMPLETED' => 'succeeded', 'PENDING' => 'processing', 'DECLINED' => 'canceled'], $statuses);
    }

    #[Test]
    public function an_order_paypal_no_longer_has_reads_as_gone(): void
    {
        $reservation = $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'ORDER-OLD']);
        $this->orders->shouldReceive('getOrder')->andReturn($this->paypalError(404, 'RESOURCE_NOT_FOUND', ['INVALID_RESOURCE_ID']));
        $this->payments->shouldReceive('getCapturedPayment')->andReturn($this->paypalError(404, 'RESOURCE_NOT_FOUND', ['INVALID_RESOURCE_ID']));

        $this->assertNull($this->gateway()->retrievePaymentIntent('ORDER-OLD', $reservation));
    }

    #[Test]
    public function a_transient_order_lookup_failure_throws(): void
    {
        $reservation = $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('getOrder')->andReturn($this->paypalError(503, 'SERVICE_UNAVAILABLE'));
        $this->payments->shouldNotReceive('getCapturedPayment');

        $this->expectException(\RuntimeException::class);

        $this->gateway()->retrievePaymentIntent('ORDER-1', $reservation);
    }

    #[Test]
    public function an_uncaptured_order_is_payable_only_while_the_reservation_can_still_be_captured(): void
    {
        // PayPal has no void for CAPTURE orders; the capture route refusing the order once the
        // reservation leaves a payable state is the void, so retrieve must report it canceled.
        $this->orders->shouldReceive('getOrder')
            ->andReturn($this->paypalSuccess(Order::class, ['id' => 'ORDER-1', 'status' => 'APPROVED']));

        $statuses = [];
        foreach (['pending', 'awaiting_payment', 'confirmed', 'cancelled', 'expired'] as $status) {
            $intent = $this->gateway()->retrievePaymentIntent('ORDER-1', $this->reservation(['status' => $status, 'payment_id' => 'ORDER-1']));
            $statuses[$status] = $intent->status;
        }

        // A resumed order is re-mounted around the same order id (the JS SDK's client secret).
        $this->assertSame('ORDER-1', $intent->client_secret);

        $this->assertSame([
            'pending' => 'requires_payment_method',
            'awaiting_payment' => 'requires_payment_method',
            'confirmed' => 'canceled',
            'cancelled' => 'canceled',
            'expired' => 'canceled',
        ], $statuses);
    }

    #[Test]
    public function a_stored_card_order_is_replaced_on_resume_instead_of_mounted_on_the_paypal_button(): void
    {
        // The view mounts the resumed order on the PayPal button, which must not pay a card order.
        $this->orders->shouldReceive('getOrder')->andReturn($this->paypalSuccess(Order::class, [
            'id' => 'ORDER-CARD',
            'status' => 'APPROVED',
            'payment_source' => ['card' => ['brand' => 'VISA', 'last_digits' => '5556']],
        ]));

        $intent = $this->gateway()->retrievePaymentIntent('ORDER-CARD', $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'ORDER-CARD']));

        $this->assertSame('canceled', $intent->status);
    }

    #[Test]
    public function a_completed_order_is_judged_by_its_capture_not_the_order_status(): void
    {
        // Reached when the capture route never stored the capture id: an ACDC processor decline
        // leaves a COMPLETED order with a DECLINED capture, which must read as replaceable.
        $reservation = $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'ORDER-1']);
        $order = fn (?string $captureStatus) => $this->paypalSuccess(Order::class, [
            'id' => 'ORDER-1',
            'status' => 'COMPLETED',
            'purchase_units' => $captureStatus === null ? [] : [[
                'payments' => ['captures' => [['id' => 'CAPTURE-1', 'status' => $captureStatus]]],
            ]],
        ]);

        $statuses = [];
        foreach (['COMPLETED', 'DECLINED', 'FAILED', 'PENDING', null] as $captureStatus) {
            $this->orders->shouldReceive('getOrder')->once()->andReturn($order($captureStatus));
            $statuses[$captureStatus ?? 'unreadable'] = $this->gateway()->retrievePaymentIntent('ORDER-1', $reservation)->status;
        }

        $this->assertSame([
            'COMPLETED' => 'succeeded',
            'DECLINED' => 'canceled',
            'FAILED' => 'canceled',
            'PENDING' => 'processing',
            'unreadable' => 'processing',
        ], $statuses);
    }

    #[Test]
    public function an_out_of_band_settlement_drops_the_never_capturable_order(): void
    {
        $reservation = $this->reservation(['status' => 'confirmed', 'payment_id' => 'ORDER-OPEN']);
        $this->orders->shouldReceive('getOrder')
            ->andReturn($this->paypalSuccess(Order::class, ['id' => 'ORDER-OPEN', 'status' => 'CREATED']));

        app(ReservationRefundProcessor::class)->settlePaidOutOfBand($reservation);

        $this->assertSame('', $reservation->fresh()->payment_id);
        $this->assertFalse((bool) $reservation->fresh()->payment_unresolved);
    }

    #[Test]
    public function an_order_creation_error_throws_a_paypal_error(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => '']);
        $this->orders->shouldReceive('createOrder')->andReturn($this->paypalError(422, 'UNPROCESSABLE_ENTITY', ['DECIMAL_PRECISION']));

        try {
            $this->gateway()->paymentIntent($reservation->amountDue(), $reservation, collect());
            $this->fail('An order creation error was not raised.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('DECIMAL_PRECISION', $e->getMessage());
        }
    }

    #[Test]
    public function a_webhook_confirming_through_custom_id_stores_the_capture_id(): void
    {
        // The capture route never stored the capture id (e.g. its PayPal call timed out after
        // PayPal captured), so payment_id still holds the order id and refunds would target it.
        Event::fake([ReservationConfirmed::class]);
        $verifier = Mockery::mock(WebhookSignatureVerifier::class);
        $verifier->shouldReceive('verify')->andReturn(true);

        $reservation = $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'ORDER-1']);
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 'WH-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAPTURE-1',
                'status' => 'COMPLETED',
                'custom_id' => (string) $reservation->id,
                'amount' => ['currency_code' => 'EUR', 'value' => '55.00'],
            ],
        ]));

        (new PaypalPaymentGateway($verifier))->verifyPayment($request);

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertSame('CAPTURE-1', $reservation->fresh()->payment_id);
        Event::assertDispatchedTimes(ReservationConfirmed::class, 1);
    }
}
