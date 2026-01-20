<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Mockery;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Http\Payment\WebhookSignatureVerifier;
use Reach\ResrvPaymentPaypal\Tests\TestCase;
use Reach\StatamicResrv\Models\Reservation;

class HandleRedirectBackSecurityTest extends TestCase
{
    protected PaypalPaymentGateway $gateway;

    protected $mockClient;

    protected $mockOrdersController;

    protected $mockWebhookVerifier;

    protected $rateLimiter;

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

        $this->rateLimiter = app(RateLimiter::class);
        $this->rateLimiter->clear('paypal-capture:127.0.0.1');
    }

    protected function tearDown(): void
    {
        // Clear rate limits between tests
        $this->rateLimiter->clear('paypal-capture:127.0.0.1');
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_blocks_requests_after_rate_limit_exceeded(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        // Hit rate limit 10 times
        $rateLimitKey = 'paypal-capture:127.0.0.1';
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->hit($rateLimitKey, 60);
        }

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals(123, $result['reservation']['id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_allows_requests_under_rate_limit(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        // Hit rate limit 9 times (under the limit of 10)
        $rateLimitKey = 'paypal-capture:127.0.0.1';
        for ($i = 0; $i < 9; $i++) {
            $this->rateLimiter->hit($rateLimitKey, 60);
        }

        // Mock successful order retrieval with matching reference_id
        $this->mockOrderGet('test-token', '123');
        $this->mockOrderCapture('test-token', 'COMPLETED', 'capture-123');

        $result = $this->gateway->handleRedirectBack();

        $this->assertTrue($result['status']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_prevents_capture_when_reference_id_mismatches(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        // Mock order retrieval with DIFFERENT reference_id (attack scenario)
        $this->mockOrderGet('test-token', '999');

        // captureOrder should NOT be called
        $this->mockOrdersController->shouldNotReceive('captureOrder');

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_penalizes_heavily_on_reference_id_mismatch(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        // Mock order retrieval with mismatched reference_id
        $this->mockOrderGet('test-token', '999');

        $rateLimitKey = 'paypal-capture:127.0.0.1';

        // Verify rate limit is empty before
        $this->assertEquals(0, $this->rateLimiter->attempts($rateLimitKey));

        $this->gateway->handleRedirectBack();

        // Should have 3 strikes (heavy penalty)
        $this->assertEquals(3, $this->rateLimiter->attempts($rateLimitKey));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_allows_capture_when_reference_id_matches(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        // Mock order retrieval with MATCHING reference_id
        $this->mockOrderGet('test-token', '123');
        $this->mockOrderCapture('test-token', 'COMPLETED', 'capture-123');

        $result = $this->gateway->handleRedirectBack();

        $this->assertTrue($result['status']);
        $this->assertEquals('capture-123', $result['reservation']['payment_id']);
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_clears_rate_limit_on_successful_capture(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        $rateLimitKey = 'paypal-capture:127.0.0.1';

        // Add some rate limit hits
        $this->rateLimiter->hit($rateLimitKey, 60);
        $this->rateLimiter->hit($rateLimitKey, 60);
        $this->assertEquals(2, $this->rateLimiter->attempts($rateLimitKey));

        // Mock successful capture
        $this->mockOrderGet('test-token', '123');
        $this->mockOrderCapture('test-token', 'COMPLETED', 'capture-123');

        $this->gateway->handleRedirectBack();

        // Rate limit should be cleared
        $this->assertEquals(0, $this->rateLimiter->attempts($rateLimitKey));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_does_not_penalize_on_paypal_api_failure(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        // Mock API failure
        $this->mockOrdersController->shouldReceive('getOrder')
            ->once()
            ->andThrow(new \Exception('PayPal API unavailable'));

        $rateLimitKey = 'paypal-capture:127.0.0.1';

        $this->gateway->handleRedirectBack();

        // Should NOT be penalized for API failure
        $this->assertEquals(0, $this->rateLimiter->attempts($rateLimitKey));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_increments_rate_limit_on_valid_attempt(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'test-token']);

        $this->mockOrderGet('test-token', '123');
        $this->mockOrderCapture('test-token', 'PENDING', null);

        $rateLimitKey = 'paypal-capture:127.0.0.1';

        $this->assertEquals(0, $this->rateLimiter->attempts($rateLimitKey));

        $this->gateway->handleRedirectBack();

        // Should have 1 normal hit (but cleared if completed, so check pending case)
        // For PENDING status, rate limit is hit but not cleared
        $this->assertEquals(1, $this->rateLimiter->attempts($rateLimitKey));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_handles_cancelled_payment_without_rate_limit_hit(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'cancelled' => 'true']);

        $rateLimitKey = 'paypal-capture:127.0.0.1';

        $result = $this->gateway->handleRedirectBack();

        $this->assertFalse($result['status']);
        $this->assertEquals(0, $this->rateLimiter->attempts($rateLimitKey));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function it_blocks_after_multiple_reference_id_mismatches(): void
    {
        $this->setupMockReservation(123);
        $this->simulateRequest(['id' => 123, 'token' => 'attack-token']);

        // Each mismatch = 3 strikes. Limit is 10.
        // After 4 mismatches = 12 strikes, 5th attempt should be blocked.
        // So ordersGet will be called 4 times before rate limit kicks in.
        $this->mockOrderGetTimes('attack-token', '999', 4);

        // First mismatch attempt (3 strikes)
        $this->gateway->handleRedirectBack();

        // Second mismatch attempt (3 more strikes = 6 total)
        $this->gateway->handleRedirectBack();

        // Third mismatch attempt (3 more strikes = 9 total)
        $this->gateway->handleRedirectBack();

        // Fourth mismatch attempt (3 more strikes = 12 total)
        $this->gateway->handleRedirectBack();

        // Fifth attempt should be blocked before even calling ordersGet
        $result = $this->gateway->handleRedirectBack();

        // Should be rate limited now
        $this->assertFalse($result['status']);

        // Verify we're blocked (at 12 attempts, which exceeds limit of 10)
        $rateLimitKey = 'paypal-capture:127.0.0.1';
        $this->assertGreaterThanOrEqual(10, $this->rateLimiter->attempts($rateLimitKey));
    }

    protected function setupMockReservation(int $id): void
    {
        $this->mockReservation = Mockery::mock('alias:'.Reservation::class);
        $this->mockReservation->id = $id;
        $this->mockReservation->payment_id = null;

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

        $this->mockReservation->shouldReceive('update')
            ->andReturnUsing(function ($data) {
                if (isset($data['payment_id'])) {
                    $this->mockReservation->payment_id = $data['payment_id'];
                }

                return true;
            });

        $this->mockReservation->shouldReceive('fresh')
            ->andReturnSelf();
    }

    protected function simulateRequest(array $params): void
    {
        $request = Request::create('/', 'GET', $params);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $this->app->instance('request', $request);
    }

    protected function mockOrderGet(string $token, string $referenceId): void
    {
        $purchaseUnit = Mockery::mock();
        $purchaseUnit->shouldReceive('getReferenceId')->andReturn($referenceId);

        $order = Mockery::mock();
        $order->shouldReceive('getPurchaseUnits')->andReturn([$purchaseUnit]);

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($order);

        $this->mockOrdersController->shouldReceive('getOrder')
            ->with(['id' => $token])
            ->andReturn($response);
    }

    protected function mockOrderGetTimes(string $token, string $referenceId, int $times): void
    {
        $purchaseUnit = Mockery::mock();
        $purchaseUnit->shouldReceive('getReferenceId')->andReturn($referenceId);

        $order = Mockery::mock();
        $order->shouldReceive('getPurchaseUnits')->andReturn([$purchaseUnit]);

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($order);

        $this->mockOrdersController->shouldReceive('getOrder')
            ->with(['id' => $token])
            ->times($times)
            ->andReturn($response);
    }

    protected function mockOrderCapture(string $token, string $status, ?string $captureId): void
    {
        $result = Mockery::mock();
        $result->shouldReceive('getStatus')->andReturn($status);

        if ($captureId) {
            $capture = Mockery::mock();
            $capture->shouldReceive('getId')->andReturn($captureId);

            $payments = Mockery::mock();
            $payments->shouldReceive('getCaptures')->andReturn([$capture]);

            $purchaseUnit = Mockery::mock();
            $purchaseUnit->shouldReceive('getPayments')->andReturn($payments);

            $result->shouldReceive('getPurchaseUnits')->andReturn([$purchaseUnit]);
        }

        $response = Mockery::mock(ApiResponse::class);
        $response->shouldReceive('getResult')->andReturn($result);

        $this->mockOrdersController->shouldReceive('captureOrder')
            ->with(['id' => $token])
            ->andReturn($response);
    }
}
