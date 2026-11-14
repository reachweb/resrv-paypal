<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Tests\BootedAddonTestCase;

/**
 * POST /resrv-paypal/capture/{orderId} through the route the add-on registers on boot, against the
 * responses paypal-server-sdk 2.x really returns.
 */
class CaptureRouteTest extends BootedAddonTestCase
{
    protected $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = Mockery::mock(OrdersController::class);
        $client = Mockery::mock(PaypalServerSdkClient::class);
        $client->shouldReceive('getOrdersController')->andReturn($this->orders);
        $this->app->instance(PaypalServerSdkClient::class, $client);
    }

    protected function completedOrder(string $captureStatus = 'COMPLETED'): array
    {
        return [
            'id' => 'ORDER-1',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => '1',
                'payments' => ['captures' => [[
                    'id' => 'CAPTURE-1',
                    'status' => $captureStatus,
                    'amount' => ['currency_code' => 'EUR', 'value' => '55.00'],
                ]]],
            ]],
        ];
    }

    #[Test]
    public function a_checkout_capture_asks_for_the_full_representation_and_stores_the_capture_id(): void
    {
        // PayPal documents the default Prefer: return=minimal response as id, status and links
        // only — no purchase_units — so the capture id can only be read from a full representation.
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('captureOrder')
            ->once()
            ->with(Mockery::on(fn ($options) => $options['id'] === 'ORDER-1' && ($options['prefer'] ?? null) === 'return=representation'))
            ->andReturn($this->paypalSuccess(Order::class, $this->completedOrder(), 201));

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertOk()
            ->assertJson(['status' => 'COMPLETED', 'captureId' => 'CAPTURE-1']);

        $this->assertSame('CAPTURE-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_card_decline_returns_a_clean_decline_response(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('captureOrder')
            ->andReturn($this->paypalError(422, 'UNPROCESSABLE_ENTITY', ['INSTRUMENT_DECLINED']));

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertStatus(400)
            ->assertJson(['error' => 'Payment declined']);

        $this->assertSame('ORDER-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_retried_capture_of_an_already_captured_order_reports_the_capture(): void
    {
        // The first attempt captured but its response never arrived (timeout); the retry must not
        // tell a paying customer the payment was declined.
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('captureOrder')
            ->andReturn($this->paypalError(422, 'UNPROCESSABLE_ENTITY', ['ORDER_ALREADY_CAPTURED']));
        $this->orders->shouldReceive('getOrder')->with(['id' => 'ORDER-1'])
            ->andReturn($this->paypalSuccess(Order::class, $this->completedOrder()));

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertOk()
            ->assertJson(['status' => 'COMPLETED', 'captureId' => 'CAPTURE-1']);

        $this->assertSame('CAPTURE-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_paypal_outage_is_not_reported_as_a_decline_and_leaks_nothing(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('captureOrder')->andReturn($this->paypalError(503, 'SERVICE_UNAVAILABLE'));

        $response = $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1');

        $response->assertStatus(502)->assertJson(['error' => 'Capture failed']);
        $this->assertStringNotContainsString('SERVICE_UNAVAILABLE', $response->getContent());
    }

    #[Test]
    public function a_pending_capture_is_reported_as_pending_and_stored(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('captureOrder')->andReturn($this->paypalSuccess(Order::class, $this->completedOrder('PENDING'), 201));

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertOk()
            ->assertJson(['status' => 'PENDING', 'captureId' => 'CAPTURE-1', 'reservationId' => $reservation->id]);

        $this->assertSame('CAPTURE-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_capture_declined_inside_a_completed_order_asks_for_a_new_order(): void
    {
        // The order is consumed, so the JS must mint a fresh one; the dead capture id is stored so
        // retrievePaymentIntent() reads it as canceled and Resrv replaces it.
        $reservation = $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('captureOrder')->andReturn($this->paypalSuccess(Order::class, $this->completedOrder('DECLINED'), 201));

        $this->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertStatus(400)
            ->assertJson(['error' => 'Payment declined', 'requiresNewOrder' => true]);

        $this->assertSame('CAPTURE-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_transport_failure_returns_a_generic_retryable_error(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('captureOrder')->andThrow(new \RuntimeException('cURL error 28: timed out after 15000 ms'));

        $response = $this->withSession(['resrv_reservation' => $reservation->id])->postJson('/resrv-paypal/capture/ORDER-1');

        $response->assertStatus(502)->assertJson(['error' => 'Capture failed']);
        $this->assertStringNotContainsString('cURL', $response->getContent());
        $this->assertSame('ORDER-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function an_unknown_order_is_refused_without_calling_paypal(): void
    {
        $this->orders->shouldNotReceive('captureOrder');

        $this->postJson('/resrv-paypal/capture/ORDER-NOPE')->assertForbidden();
    }

    #[Test]
    public function only_a_payable_reservation_can_be_captured(): void
    {
        $this->orders->shouldReceive('captureOrder')->andReturn($this->paypalSuccess(Order::class, $this->completedOrder(), 201));

        $cases = [
            'pending, owning session' => [['status' => 'pending'], true],
            'pending, no session' => [['status' => 'pending'], false],
            'awaiting payment, hold open, no session' => [['status' => 'awaiting_payment', 'hold_expires_at' => now()->addDay()], false],
            'awaiting payment, hold passed' => [['status' => 'awaiting_payment', 'hold_expires_at' => now()->subMinute()], false],
            // A transition that committed first (admin marked paid / cancelled) voids the order.
            'confirmed, owning session' => [['status' => 'confirmed'], true],
            'cancelled, owning session' => [['status' => 'cancelled'], true],
        ];

        $results = [];
        foreach ($cases as $label => [$attributes, $withSession]) {
            $reservation = $this->reservation(array_merge(['payment_id' => 'ORDER-1'], $attributes));
            $request = $withSession ? $this->withSession(['resrv_reservation' => $reservation->id]) : $this->flushSession();
            $results[$label] = $request->postJson('/resrv-paypal/capture/ORDER-1')->status();
            $reservation->delete();
        }

        $this->assertSame([
            'pending, owning session' => 200,
            'pending, no session' => 403,
            'awaiting payment, hold open, no session' => 200,
            'awaiting payment, hold passed' => 403,
            'confirmed, owning session' => 403,
            'cancelled, owning session' => 403,
        ], $results);
    }
}
