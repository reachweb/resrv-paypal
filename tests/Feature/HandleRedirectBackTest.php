<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Illuminate\Http\Request;
use Mockery;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Models\CapturedPayment;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Tests\BootedAddonTestCase;

/**
 * handleRedirectBack() on the public checkout-complete page: the JS returns the customer with the
 * capture id the capture route stored, and the result is re-verified with PayPal (the webhook stays
 * the source of truth).
 */
class HandleRedirectBackTest extends BootedAddonTestCase
{
    protected $payments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = Mockery::mock(PaymentsController::class);
        $client = Mockery::mock(PaypalServerSdkClient::class);
        $client->shouldReceive('getPaymentsController')->andReturn($this->payments);
        $this->app->instance(PaypalServerSdkClient::class, $client);
    }

    protected function redirectBack(array $query): array
    {
        $this->app->instance('request', Request::create('/checkout-complete', 'GET', $query));

        return (new PaypalPaymentGateway)->handleRedirectBack();
    }

    protected function captureReads(string $status): void
    {
        $this->payments->shouldReceive('getCapturedPayment')->with(['captureId' => 'CAPTURE-7'])
            ->andReturn($this->paypalSuccess(CapturedPayment::class, ['id' => 'CAPTURE-7', 'status' => $status]));
    }

    #[Test]
    public function a_completed_capture_shows_success_with_its_reservation(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'CAPTURE-7']);
        $this->captureReads('COMPLETED');

        $result = $this->redirectBack(['capture_id' => 'CAPTURE-7']);

        $this->assertTrue($result['status']);
        $this->assertSame($reservation->id, $result['reservation']['id']);
    }

    #[Test]
    public function a_capture_that_did_not_complete_shows_failure(): void
    {
        $this->reservation(['status' => 'pending', 'payment_id' => 'CAPTURE-7']);
        $this->captureReads('DECLINED');

        $this->assertFalse($this->redirectBack(['capture_id' => 'CAPTURE-7'])['status']);
    }

    #[Test]
    public function the_reservation_row_id_never_resolves_a_reservation(): void
    {
        // The page is public and row ids are sequential: resolving by them exposed any
        // customer's reservation to anyone enumerating ?id=.
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'CAPTURE-7']);
        $this->payments->shouldNotReceive('getCapturedPayment');

        $this->assertSame(['status' => false, 'reservation' => []], $this->redirectBack(['id' => $reservation->id]));
        $this->assertSame(['status' => false, 'reservation' => []], $this->redirectBack(['capture_id' => 'CAPTURE-UNKNOWN']));
        $this->assertSame(['status' => false, 'reservation' => []], $this->redirectBack([]));
    }

    #[Test]
    public function a_paypal_error_falls_back_to_the_webhook_confirmed_status(): void
    {
        $this->reservation(['status' => 'confirmed', 'payment_id' => 'CAPTURE-7']);
        $this->payments->shouldReceive('getCapturedPayment')->andReturn($this->paypalError(503, 'SERVICE_UNAVAILABLE'));

        $this->assertTrue($this->redirectBack(['capture_id' => 'CAPTURE-7'])['status']);
    }

    #[Test]
    public function a_pending_payment_is_reported_as_pending(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'CAPTURE-7']);

        $this->assertSame('pending', $this->redirectBack(['payment_pending' => $reservation->id])['status']);
    }
}
