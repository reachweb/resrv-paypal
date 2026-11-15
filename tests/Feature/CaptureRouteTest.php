<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Tests\BootedAddonTestCase;

/**
 * POST /resrv-paypal/capture/{orderId} and /resrv-paypal/order/{orderId}/{source} through the routes
 * the add-on registers on boot, against the responses paypal-server-sdk 2.x really returns.
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

        // The capture route reads the order before capturing it. A wallet order carries no 3-D
        // Secure result to judge.
        $this->orders->shouldReceive('getOrder')->byDefault()->andReturn($this->paypalSuccess(Order::class, [
            'id' => 'ORDER-1',
            'status' => 'APPROVED',
            'payment_source' => ['paypal' => ['email_address' => 'buyer@example.com']],
        ]));
    }

    /** An approved card order with the 3-D Secure result PayPal reports, or none. */
    protected function cardOrder(?string $liabilityShift, ?string $enrollmentStatus = null, ?string $authenticationStatus = null)
    {
        $card = ['brand' => 'VISA', 'last_digits' => '1368'];

        if ($liabilityShift !== null) {
            $card['authentication_result'] = [
                'liability_shift' => $liabilityShift,
                'three_d_secure' => array_filter([
                    'enrollment_status' => $enrollmentStatus,
                    'authentication_status' => $authenticationStatus,
                ]),
            ];
        }

        return $this->paypalSuccess(Order::class, ['id' => 'ORDER-1', 'status' => 'APPROVED', 'payment_source' => ['card' => $card]]);
    }

    /** The create-order body PayPal receives, as JSON. */
    protected function expectOrderCreation(string $newOrderId, ?array &$body)
    {
        return $this->orders->shouldReceive('createOrder')->once()->andReturnUsing(function ($options) use ($newOrderId, &$body) {
            $body = json_decode(json_encode($options['body']), true);

            return $this->paypalSuccess(Order::class, ['id' => $newOrderId, 'status' => 'CREATED'], 201);
        });
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

    #[Test]
    public function a_card_order_is_captured_only_when_its_3ds_outcome_allows_it(): void
    {
        // PayPal's recommended action for each liability_shift / enrollment_status /
        // authentication_status it reports (3-D Secure response parameters). PayPal captures
        // every one of these if asked, so the route must refuse before capturing.
        $cases = [
            'step-up succeeded' => ['POSSIBLE', 'Y', 'Y'],
            'attempted' => ['POSSIBLE', 'Y', 'A'],
            'step-up failed' => ['NO', 'Y', 'N'],
            'rejected' => ['NO', 'Y', 'R'],
            'issuer unavailable' => ['NO', 'Y', 'U'],
            'enrolled, no authentication' => ['NO', 'Y', null],
            'system unavailable' => ['UNKNOWN', 'Y', 'C'],
            'lookup unavailable, unknown' => ['UNKNOWN', 'U', null],
            'not enrolled' => ['NO', 'N', null],
            'lookup unavailable' => ['NO', 'U', null],
            'bypassed' => ['NO', 'B', null],
            '3-D Secure not performed' => [null],
        ];

        $order = null;
        $captured = false;
        $this->orders->shouldReceive('getOrder')->andReturnUsing(function () use (&$order) {
            return $order;
        });
        $this->orders->shouldReceive('captureOrder')->andReturnUsing(function () use (&$captured) {
            $captured = true;

            return $this->paypalSuccess(Order::class, $this->completedOrder(), 201);
        });

        $results = [];
        foreach ($cases as $label => $authentication) {
            $order = $this->cardOrder(...$authentication);
            $captured = false;
            $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);

            $response = $this->withSession(['resrv_reservation' => $reservation->id])->postJson('/resrv-paypal/capture/ORDER-1');

            $results[$label] = $captured ? 'captured' : $response->status().' '.$response->json('error');
            if (! $captured) {
                $this->assertSame('ORDER-1', $reservation->fresh()->payment_id, $label);
            }
            $reservation->delete();
        }

        $refused = '400 Card authentication failed';
        $this->assertSame([
            'step-up succeeded' => 'captured',
            'attempted' => 'captured',
            'step-up failed' => $refused,
            'rejected' => $refused,
            'issuer unavailable' => $refused,
            'enrolled, no authentication' => $refused,
            'system unavailable' => $refused,
            'lookup unavailable, unknown' => $refused,
            'not enrolled' => 'captured',
            'lookup unavailable' => 'captured',
            'bypassed' => 'captured',
            '3-D Secure not performed' => 'captured',
        ], $results);
    }

    #[Test]
    public function an_order_that_cannot_be_read_before_capture_is_not_captured(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('getOrder')->andReturn($this->paypalError(503, 'SERVICE_UNAVAILABLE'));
        $this->orders->shouldNotReceive('captureOrder');

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertStatus(502)
            ->assertJson(['error' => 'Capture failed']);

        $this->assertSame('ORDER-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_card_submit_replaces_the_order_with_one_that_requests_3ds(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->expectOrderCreation('ORDER-CARD', $body);

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->withHeader('referer', 'https://site.test/checkout?step=3')
            ->postJson('/resrv-paypal/order/ORDER-1/card')
            ->assertOk()
            ->assertExactJson(['id' => 'ORDER-CARD']);

        // PayPal reads the 3-D Secure request from the create-order body; SCA_WHEN_REQUIRED unless configured.
        $this->assertSame([
            'card' => [
                'attributes' => ['verification' => ['method' => 'SCA_WHEN_REQUIRED']],
                'experience_context' => [
                    'return_url' => 'https://site.test/checkout?step=3',
                    'cancel_url' => 'https://site.test/checkout?step=3',
                ],
            ],
        ], $body['payment_source']);
        // Payment + surcharge, with custom_id for the webhook's reconciliation.
        $this->assertSame(['currency_code' => 'EUR', 'value' => '55.00'], $body['purchase_units'][0]['amount']);
        $this->assertSame((string) $reservation->id, $body['purchase_units'][0]['custom_id']);
        $this->assertSame('ORDER-CARD', $reservation->fresh()->payment_id);

        // The replaced wallet order can no longer be captured.
        $this->orders->shouldNotReceive('captureOrder');
        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertForbidden();
    }

    #[Test]
    public function the_3ds_verification_method_follows_the_configuration(): void
    {
        config(['resrv-paypal.sca_method' => 'SCA_ALWAYS']);
        $reservation = $this->reservation(['status' => 'awaiting_payment', 'payment_id' => 'ORDER-1', 'hold_expires_at' => now()->addDay()]);
        $this->expectOrderCreation('ORDER-CARD', $body);

        $this->postJson('/resrv-paypal/order/ORDER-1/card')->assertOk();

        $this->assertSame('SCA_ALWAYS', $body['payment_source']['card']['attributes']['verification']['method']);
    }

    #[Test]
    public function the_paypal_button_swaps_a_card_order_back_for_a_wallet_order(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-CARD']);
        $this->expectOrderCreation('ORDER-2', $body);

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/order/ORDER-CARD/paypal')
            ->assertOk()
            ->assertExactJson(['id' => 'ORDER-2']);

        $this->assertArrayNotHasKey('payment_source', $body);
        $this->assertSame('55.00', $body['purchase_units'][0]['amount']['value']);
        $this->assertSame('ORDER-2', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function an_order_replacement_is_authorised_like_a_capture(): void
    {
        $this->orders->shouldNotReceive('createOrder');
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);

        $this->postJson('/resrv-paypal/order/ORDER-NOPE/card')->assertForbidden();
        $this->postJson('/resrv-paypal/order/ORDER-1/card')->assertForbidden();
        $this->withSession(['resrv_reservation' => $reservation->id])->postJson('/resrv-paypal/order/ORDER-1/venmo')->assertNotFound();

        $this->assertSame('ORDER-1', $reservation->fresh()->payment_id);
    }

    #[Test]
    public function a_failed_order_replacement_keeps_the_current_order(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);
        $this->orders->shouldReceive('createOrder')->andReturn($this->paypalError(422, 'UNPROCESSABLE_ENTITY', ['INVALID_PARAMETER_VALUE']));

        $response = $this->withSession(['resrv_reservation' => $reservation->id])->postJson('/resrv-paypal/order/ORDER-1/card');

        $response->assertStatus(502)->assertJson(['error' => 'Order failed']);
        $this->assertStringNotContainsString('INVALID_PARAMETER_VALUE', $response->getContent());
        $this->assertSame('ORDER-1', $reservation->fresh()->payment_id);
    }
}
