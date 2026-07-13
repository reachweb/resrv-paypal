<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Exceptions\ApiException;
use PaypalServerSdkLib\Exceptions\ErrorException;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\Http\HttpRequest;
use PaypalServerSdkLib\Http\HttpResponse;
use PaypalServerSdkLib\Models\ErrorDetails;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\TestCase;
use Reach\StatamicResrv\Events\ReservationConfirmed;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Models\Reservation;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    public function it_returns_name(): void
    {
        $this->assertEquals('paypal', $this->gateway->name());
    }

    #[Test]
    public function it_returns_label(): void
    {
        $this->assertEquals('PayPal', $this->gateway->label());
    }

    #[Test]
    public function it_returns_payment_view(): void
    {
        $this->assertEquals('resrv-paypal::livewire.checkout-payment', $this->gateway->paymentView());
    }

    #[Test]
    public function it_does_not_support_manual_confirmation(): void
    {
        $this->assertFalse($this->gateway->supportsManualConfirmation());
    }

    #[Test]
    public function it_supports_automatic_refunds(): void
    {
        // refund() moves money through PayPal's API, so customer self-cancellation may
        // rely on it. Returning false would hide the customer cancel button entirely.
        $this->assertTrue($this->gateway->supportsAutomaticRefunds());
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
                if (! isset($args['body'])) {
                    return false;
                }

                // The reservation id must ride along as custom_id (as well as reference_id) so a
                // capture webhook can be reconciled back to its reservation even after payment_id
                // has been cleared by Resrv.
                $purchaseUnit = $args['body']->getPurchaseUnits()[0];

                return $purchaseUnit->getCustomId() === '123'
                    && $purchaseUnit->getReferenceId() === '123';
            }))
            ->andReturn($response);

        $result = $this->gateway->paymentIntent($payment, $reservation, []);

        // JS SDK flow: id and client_secret both contain the order ID
        $this->assertEquals('ORDER-123', $result->id);
        $this->assertEquals('ORDER-123', $result->client_secret);
    }

    #[Test]
    public function it_ignores_a_query_string_return_url_in_payment_intent(): void
    {
        // Step 12: the pay-by-link surface passes a $returnUrl that already carries its
        // ?ref=&hash= authentication pair. This inline gateway must not bake it (or any return
        // URL) into the PayPal order — the JS SDK owns the redirect leg — so no server-side
        // append can ever corrupt the HMAC pair.
        $payment = Mockery::mock();
        $payment->shouldReceive('format')->andReturn('100.00');

        $entry = new \stdClass;
        $entry->title = 'Test Reservation';

        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->shouldReceive('entry')->andReturn($entry);
        $reservation->id = 123;

        $this->app->instance(PaypalServerSdkClient::class, $this->mockClient);

        $orderResult = Mockery::mock();
        $orderResult->shouldReceive('getId')->andReturn('ORDER-123');

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($orderResult);

        $this->mockOrdersController->shouldReceive('createOrder')
            ->once()
            ->with(Mockery::on(function ($args) {
                // The only places a return URL could ride on a v2 order are the (deprecated)
                // application context and the payment source experience context — both must
                // stay unset.
                return isset($args['body'])
                    && $args['body']->getApplicationContext() === null
                    && $args['body']->getPaymentSource() === null;
            }))
            ->andReturn($response);

        $result = $this->gateway->paymentIntent(
            $payment,
            $reservation,
            [],
            'https://example.com/pay?ref=RSV-1&hash=abc123'
        );

        $this->assertEquals('ORDER-123', $result->id);
        $this->assertEquals('ORDER-123', $result->client_secret);
    }

    #[Test]
    public function it_resumes_a_still_payable_order(): void
    {
        // An interrupted payment whose stored order is still open must resume on the SAME order id
        // (returned as client_secret for the JS SDK) rather than minting a second one.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();

        $order = Mockery::mock();
        $order->shouldReceive('getStatus')->andReturn('APPROVED');

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($order);

        $this->mockOrdersController->shouldReceive('getOrder')
            ->once()
            ->with(['id' => 'ORDER-123'])
            ->andReturn($response);

        $result = $this->gateway->retrievePaymentIntent('ORDER-123', $reservation);

        $this->assertEquals('ORDER-123', $result->id);
        $this->assertEquals('ORDER-123', $result->client_secret);
        // Any non-magic status resumes; it must not be succeeded/processing/canceled.
        $this->assertNotContains($result->status, ['succeeded', 'processing', 'canceled']);
    }

    /**
     * Build a getOrder response mock with the given order status; when $captureStatus is
     * non-null the order carries a single capture with that status (the drill-down
     * retrievePaymentIntent runs for COMPLETED orders), otherwise no purchase units at all.
     */
    protected function mockOrderResponse(string $orderStatus, ?string $captureStatus = null): object
    {
        $order = Mockery::mock();
        $order->shouldReceive('getStatus')->andReturn($orderStatus);

        if ($captureStatus === null) {
            $order->shouldReceive('getPurchaseUnits')->andReturn([]);
        } else {
            $capture = Mockery::mock();
            $capture->shouldReceive('getStatus')->andReturn($captureStatus);

            $payments = Mockery::mock();
            $payments->shouldReceive('getCaptures')->andReturn([$capture]);

            $purchaseUnit = Mockery::mock();
            $purchaseUnit->shouldReceive('getPayments')->andReturn($payments);

            $order->shouldReceive('getPurchaseUnits')->andReturn([$purchaseUnit]);
        }

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($order);

        return $response;
    }

    #[Test]
    public function it_reports_a_completed_order_with_a_completed_capture_as_money_already_moving(): void
    {
        // A COMPLETED order whose capture is COMPLETED was genuinely paid, so the resume logic
        // must treat it as "money moving" (status succeeded) and never resume it into a fresh
        // payable intent.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();

        $this->mockOrdersController->shouldReceive('getOrder')
            ->once()
            ->andReturn($this->mockOrderResponse('COMPLETED', 'COMPLETED'));

        $result = $this->gateway->retrievePaymentIntent('ORDER-123', $reservation);

        $this->assertEquals('succeeded', $result->status);
    }

    #[Test]
    public function it_inspects_the_capture_inside_a_completed_order_instead_of_trusting_it(): void
    {
        // ORDER-level COMPLETED is not the payment outcome: the capture inside can be DECLINED
        // or PENDING. This branch is reached when the capture controller's persist of the
        // capture id never happened (client timeout, crash) and payment_id still holds the
        // order id. Mapping a DECLINED capture to succeeded would strand an unpaid reservation
        // on a permanent "processing" screen with no way to mint a replacement order — it must
        // read canceled (fresh order, customer retries). PENDING (eCheck / risk review) and an
        // unreadable capture stay processing: money may be settling and the webhook (via its
        // custom_id fallback) resolves the outcome.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();

        $this->mockOrdersController->shouldReceive('getOrder')
            ->times(4)
            ->andReturn(
                $this->mockOrderResponse('COMPLETED', 'DECLINED'),
                $this->mockOrderResponse('COMPLETED', 'FAILED'),
                $this->mockOrderResponse('COMPLETED', 'PENDING'),
                $this->mockOrderResponse('COMPLETED'),
            );

        $this->assertEquals('canceled', $this->gateway->retrievePaymentIntent('ORDER-123', $reservation)->status);
        $this->assertEquals('canceled', $this->gateway->retrievePaymentIntent('ORDER-123', $reservation)->status);
        $this->assertEquals('processing', $this->gateway->retrievePaymentIntent('ORDER-123', $reservation)->status);
        $this->assertEquals('processing', $this->gateway->retrievePaymentIntent('ORDER-123', $reservation)->status);
    }

    #[Test]
    public function it_treats_a_captured_payment_id_as_processing_to_avoid_a_second_order(): void
    {
        // payment_id flips from order id to capture id once PaypalCaptureController runs, and the
        // pay-by-link page redirects the paid customer straight back here before the webhook
        // confirms. getOrder() 404s on a capture id — the gateway must recognise the live capture
        // and report processing so the caller does NOT mint (and let the customer pay) a 2nd order.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();

        $this->mockOrdersController->shouldReceive('getOrder')
            ->once()
            ->with(['id' => 'CAPTURE-123'])
            ->andThrow($this->paypalApiException(404));

        $capture = Mockery::mock();
        $capture->shouldReceive('getStatus')->andReturn('COMPLETED');

        $captureResponse = Mockery::mock(ApiResponse::class);
        $captureResponse->shouldReceive('getResult')->andReturn($capture);

        $this->mockPaymentsController->shouldReceive('getCapturedPayment')
            ->once()
            ->with(['captureId' => 'CAPTURE-123'])
            ->andReturn($captureResponse);

        $result = $this->gateway->retrievePaymentIntent('CAPTURE-123', $reservation);

        $this->assertEquals('succeeded', $result->status);
    }

    #[Test]
    public function it_maps_terminal_capture_failures_to_canceled_and_pending_to_processing(): void
    {
        // A DECLINED/FAILED capture is terminal at PayPal — no money moved and that capture can
        // never complete. Reporting processing would park the pay-by-link customer on a permanent
        // "processing" screen; canceled tells the caller to mint a fresh order so they can retry,
        // mirroring the webhook's DENIED/DECLINED handling. A PENDING capture (e.g. eCheck) is
        // money settling, so it stays processing.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();

        $this->mockOrdersController->shouldReceive('getOrder')
            ->times(3)
            ->andThrow($this->paypalApiException(404));

        $captureResponses = [];
        foreach (['DECLINED', 'FAILED', 'PENDING'] as $captureStatus) {
            $capture = Mockery::mock();
            $capture->shouldReceive('getStatus')->andReturn($captureStatus);

            $captureResponse = Mockery::mock(ApiResponse::class);
            $captureResponse->shouldReceive('getResult')->andReturn($capture);

            $captureResponses[] = $captureResponse;
        }

        $this->mockPaymentsController->shouldReceive('getCapturedPayment')
            ->times(3)
            ->andReturn(...$captureResponses);

        $this->assertEquals('canceled', $this->gateway->retrievePaymentIntent('CAPTURE-123', $reservation)->status);
        $this->assertEquals('canceled', $this->gateway->retrievePaymentIntent('CAPTURE-123', $reservation)->status);
        $this->assertEquals('processing', $this->gateway->retrievePaymentIntent('CAPTURE-123', $reservation)->status);
    }

    #[Test]
    public function it_returns_null_when_the_intent_is_neither_an_order_nor_a_capture(): void
    {
        // Genuinely gone (deleted / never existed): return null so the caller mints a fresh intent.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();

        $this->mockOrdersController->shouldReceive('getOrder')
            ->once()
            ->andThrow($this->paypalApiException(404));

        $this->mockPaymentsController->shouldReceive('getCapturedPayment')
            ->once()
            ->andThrow($this->paypalApiException(404));

        $this->assertNull($this->gateway->retrievePaymentIntent('GONE-123', $reservation));
    }

    #[Test]
    public function it_propagates_transient_retrieve_failures_instead_of_replacing_the_intent(): void
    {
        // A transient failure (here 500) must NOT be swallowed as null — that would let the caller
        // orphan a still-payable order behind a second one on the strength of a failed read.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();

        $this->mockOrdersController->shouldReceive('getOrder')
            ->once()
            ->andThrow($this->paypalApiException(500));

        $this->mockPaymentsController->shouldNotReceive('getCapturedPayment');

        $this->expectException(ApiException::class);

        $this->gateway->retrievePaymentIntent('ORDER-123', $reservation);
    }

    /**
     * Build a real ApiException carrying a given HTTP status. ApiException::getCode() is final
     * (it comes from \Exception) so it cannot be mocked — the status must flow through the
     * constructor's response argument.
     */
    protected function paypalApiException(int $status): ApiException
    {
        $response = Mockery::mock(HttpResponse::class);
        $response->shouldReceive('getStatusCode')->andReturn($status);

        return new ApiException('PayPal API error', Mockery::mock(HttpRequest::class), $response);
    }

    #[Test]
    public function it_cancels_payment_intent_as_a_safe_noop(): void
    {
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->id = 123;

        // PayPal has no cancel/void endpoint for an un-captured CAPTURE-intent order, so the
        // gateway must NOT call out to PayPal — it simply lets the order expire.
        $this->mockOrdersController->shouldNotReceive('captureOrder');
        $this->mockPaymentsController->shouldNotReceive('refundCapturedPayment');

        // Resrv relies on this never throwing (it has already cleared payment_id) and returning
        // nothing. $paymentId here is the PayPal order id originally returned from paymentIntent().
        $this->assertNull($this->gateway->cancelPaymentIntent('ORDER-123', $reservation));
    }

    #[Test]
    public function it_refunds_the_full_capture_with_an_idempotency_key(): void
    {
        // Mock the Reservation class with shouldIgnoreMissing to handle Eloquent's setAttribute
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->shouldReceive('getAttribute')->with('payment_id')->andReturn('CAPTURE-123');
        $reservation->id = 123;
        $reservation->payment_id = 'CAPTURE-123';

        $refundResult = Mockery::mock();
        $refundResult->shouldReceive('getId')->andReturn('REFUND-123');

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($refundResult);

        $this->mockPaymentsController->shouldReceive('refundCapturedPayment')
            ->once()
            ->with(Mockery::on(function ($args) {
                // Empty refund body = full refund of the capture (payment + surcharge, exactly
                // what was charged). Sending only `payment` would strand the surcharge at PayPal.
                $fullRefund = isset($args['body']) && $args['body']->getAmount() === null;

                // Stable per-(reservation, capture) PayPal-Request-Id so a retry after a dropped
                // connection replays the original refund instead of double-refunding or failing.
                // The variable part is hashed: PayPal caps the header at 38 single-byte
                // characters, which raw id concatenation overruns on 8-digit reservation ids.
                $expectedKey = 'resrv-rf-'.substr(hash('sha256', '123-CAPTURE-123'), 0, 29);
                $idempotent = ($args['paypalRequestId'] ?? null) === $expectedKey
                    && strlen($args['paypalRequestId']) <= 38;

                return $args['captureId'] === 'CAPTURE-123' && $fullRefund && $idempotent;
            }))
            ->andReturn($response);

        $result = $this->gateway->refund($reservation);

        $this->assertEquals('REFUND-123', $result->getId());
    }

    #[Test]
    public function it_throws_refund_failed_exception_on_error(): void
    {
        // Mock the Reservation class with shouldIgnoreMissing to handle Eloquent's setAttribute
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->shouldReceive('getAttribute')->with('payment_id')->andReturn('CAPTURE-123');
        $reservation->id = 123;
        $reservation->payment_id = 'CAPTURE-123';

        $this->mockPaymentsController->shouldReceive('refundCapturedPayment')
            ->once()
            ->andThrow(new \Exception('PayPal API error'));

        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('PayPal API error');

        $this->gateway->refund($reservation);
    }

    #[Test]
    public function it_treats_an_already_fully_refunded_capture_as_success(): void
    {
        // Past PayPal's idempotency window (or after a dashboard refund) the retry is rejected
        // with CAPTURE_FULLY_REFUNDED. The money is already back with the customer, so refund()
        // must succeed — throwing would roll back the REFUNDED transition and strand a refunded
        // charge on a live reservation forever.
        $reservation = Mockery::mock(Reservation::class)->shouldIgnoreMissing();
        $reservation->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $reservation->shouldReceive('getAttribute')->with('payment_id')->andReturn('CAPTURE-123');
        $reservation->id = 123;
        $reservation->payment_id = 'CAPTURE-123';

        $detail = Mockery::mock(ErrorDetails::class);
        $detail->shouldReceive('getIssue')->andReturn('CAPTURE_FULLY_REFUNDED');

        $exception = Mockery::mock(ErrorException::class);
        $exception->shouldReceive('getDetails')->andReturn([$detail]);

        $this->mockPaymentsController->shouldReceive('refundCapturedPayment')
            ->once()
            ->andThrow($exception);

        $this->assertTrue($this->gateway->refund($reservation));
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
    #[RunInSeparateProcess]
    public function it_skips_already_confirmed_reservation_on_completed_webhook(): void
    {
        // Regression: status is a plain string in Resrv v6, so the "already confirmed" guard must
        // compare against ReservationStatus::CONFIRMED->value. If it compared to the enum instance
        // it would never match, and a duplicate COMPLETED webhook would re-attempt confirmation.
        $this->mockWebhookVerifier->shouldReceive('verify')->once()->andReturn(true);

        $reservationAlias = Mockery::mock('alias:'.Reservation::class);
        $instance = Mockery::mock();
        $instance->id = 123;
        $instance->status = 'confirmed';
        // An already-confirmed reservation must never be re-transitioned.
        $instance->shouldNotReceive('transitionTo');

        $reservationAlias->shouldReceive('findByPaymentId')->with('CAPTURE-123')->andReturnSelf();
        $reservationAlias->shouldReceive('first')->andReturn($instance);

        $request = $this->webhookRequest('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAPTURE-123']);

        $response = $this->gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_confirms_with_webhook_context_on_completed_capture(): void
    {
        // The activity log distinguishes webhook confirmations from checkout ones via the
        // event's $via/$payment arguments — a bare dispatch() would mislabel them VIA_CHECKOUT.
        Event::fake([ReservationConfirmed::class]);

        $this->mockWebhookVerifier->shouldReceive('verify')->once()->andReturn(true);

        $expected = Mockery::mock();
        $payment = Mockery::mock();
        $payment->shouldReceive('add')->andReturn($expected);

        $reservation = Mockery::mock('alias:'.Reservation::class);
        $reservation->id = 123;
        $reservation->status = 'pending';
        $reservation->payment_gateway = 'paypal';
        $reservation->payment = $payment;
        $reservation->payment_surcharge = null;
        $reservation->shouldReceive('findByPaymentId')->with('CAPTURE-123')->andReturnSelf();
        $reservation->shouldReceive('first')->andReturnSelf();
        $reservation->shouldReceive('transitionTo')->once()->andReturn(true);

        $request = $this->webhookRequest('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAPTURE-123']);

        $response = $this->gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());

        Event::assertDispatched(
            ReservationConfirmed::class,
            fn ($event) => $event->via === ReservationConfirmed::VIA_WEBHOOK
                && $event->payment === ['gateway' => 'paypal', 'payment_id' => 'CAPTURE-123']
        );
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_leaves_reservation_pending_on_denied_capture(): void
    {
        // A denied capture is a retryable failed attempt: the reservation must stay PENDING (no
        // confirmation, no cancellation), mirroring how Stripe treats payment_intent.payment_failed.
        $this->mockWebhookVerifier->shouldReceive('verify')->once()->andReturn(true);

        $reservationAlias = Mockery::mock('alias:'.Reservation::class);
        $instance = Mockery::mock();
        $instance->id = 123;
        $instance->status = 'pending';
        $instance->shouldNotReceive('transitionTo');
        $instance->shouldNotReceive('expire');

        $reservationAlias->shouldReceive('findByPaymentId')->with('CAPTURE-123')->andReturnSelf();
        $reservationAlias->shouldReceive('first')->andReturn($instance);

        $request = $this->webhookRequest('PAYMENT.CAPTURE.DENIED', ['id' => 'CAPTURE-123']);

        $response = $this->gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_acknowledges_refund_webhook_without_changing_state(): void
    {
        // Refund/reversal lifecycle is owned by Resrv core; the webhook only acknowledges and must
        // not dispatch a cancellation or transition the reservation itself.
        $this->mockWebhookVerifier->shouldReceive('verify')->once()->andReturn(true);

        $reservationAlias = Mockery::mock('alias:'.Reservation::class);
        $instance = Mockery::mock();
        $instance->id = 123;
        $instance->status = 'pending';
        $instance->shouldNotReceive('transitionTo');
        $instance->shouldNotReceive('expire');

        $reservationAlias->shouldReceive('findByPaymentId')->with('CAPTURE-123')->andReturnSelf();
        $reservationAlias->shouldReceive('first')->andReturn($instance);

        // For refund events the capture ID is resolved from the "up" link, not resource.id.
        $request = $this->webhookRequest('PAYMENT.CAPTURE.REFUNDED', [
            'id' => 'REFUND-999',
            'links' => [
                ['rel' => 'up', 'href' => 'https://api.paypal.com/v2/payments/captures/CAPTURE-123'],
            ],
        ]);

        $response = $this->gateway->verifyPayment($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    protected function webhookRequest(string $eventType, array $resource): Request
    {
        return Request::create(
            '/',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'id' => 'WH-EVENT-1',
                'event_type' => $eventType,
                'resource' => $resource,
            ])
        );
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

        $this->expectException(HttpException::class);

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

        $this->expectException(HttpException::class);

        $this->gateway->verifyPayment($request);
    }
}
