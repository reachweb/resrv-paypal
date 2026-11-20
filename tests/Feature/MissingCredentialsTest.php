<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Tests\BootedAddonTestCase;
use Reach\StatamicResrv\Exceptions\RefundFailedException;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * A site that registers PayPal next to Stripe but leaves PAYPAL_CLIENT_ID / PAYPAL_CLIENT_SECRET
 * unset. Resrv builds every registered gateway to list them on the checkout's payment step, so
 * PayPal must construct without its credentials and fail only where it calls PayPal.
 */
class MissingCredentialsTest extends BootedAddonTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('resrv-config.payment_gateways', [
            'stripe' => ['class' => StripePaymentGateway::class],
            'paypal' => ['class' => PaypalPaymentGateway::class],
        ]);
    }

    protected function defineServicesConfig($app): void
    {
        $app['config']->set('services.paypal', [
            'client_id' => null,
            'client_secret' => null,
            'mode' => 'sandbox',
            'webhook_id' => null,
        ]);
    }

    #[Test]
    public function resrv_lists_every_gateway_when_paypal_has_no_credentials(): void
    {
        $gateways = app(PaymentGatewayManager::class)->availableForFrontend(Price::create(55));

        $this->assertSame(['stripe', 'paypal'], array_column($gateways, 'name'));
    }

    /** A `PAYPAL_CLIENT_ID=` line in .env gives an empty string, not null. */
    public static function missingCredentials(): array
    {
        return [
            'both unset' => [null, null],
            'both blank' => ['', ''],
            'secret unset' => ['client-id', null],
        ];
    }

    #[Test]
    #[DataProvider('missingCredentials')]
    public function creating_an_order_without_credentials_fails_with_a_clear_logged_error(?string $clientId, ?string $clientSecret): void
    {
        config(['services.paypal.client_id' => $clientId, 'services.paypal.client_secret' => $clientSecret]);
        $reservation = $this->reservation(['status' => 'pending']);

        $this->assertFailsWithCredentialsError(
            fn () => (new PaypalPaymentGateway)->paymentIntent(Price::create(55), $reservation, [])
        );
    }

    #[Test]
    public function choosing_paypal_at_checkout_fails_with_the_credentials_error(): void
    {
        Collection::make('pages')->routes('/{slug}')->save();
        $checkoutComplete = Entry::make()->collection('pages')->slug('checkout-complete')->data(['title' => 'Thank you']);
        $checkoutComplete->save();
        config(['resrv-config.checkout_completed_entry' => $checkoutComplete->id()]);

        session(['resrv_reservation' => $this->reservation(['status' => 'pending'])->id]);

        $checkout = Livewire::test(CheckoutWithoutView::class);

        Log::spy();
        Exceptions::fake();

        // Resrv before v6.6 lets the error through the checkout. From v6.6 the checkout reports it
        // and shows the customer its payment error. Either way it must be the credentials error:
        // Resrv's Checkout reads getPublicKey() into a string property before it creates the
        // order, so a TypeError there must not pre-empt it.
        try {
            $checkout->call('selectGateway', 'paypal');
        } catch (\RuntimeException $exception) {
            report($exception);
        }

        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'PayPal credentials are not configured'));
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'PayPal credentials are not configured'))
            ->once();
    }

    #[Test]
    public function a_refund_without_credentials_fails_as_a_refund_failure(): void
    {
        $this->expectException(RefundFailedException::class);
        $this->expectExceptionMessage('PayPal credentials are not configured');

        (new PaypalPaymentGateway)->refund($this->reservation());
    }

    #[Test]
    public function the_capture_route_answers_with_a_capture_failure_without_credentials(): void
    {
        $reservation = $this->reservation(['status' => 'pending', 'payment_id' => 'ORDER-1']);

        $this->withSession(['resrv_reservation' => $reservation->id])
            ->postJson('/resrv-paypal/capture/ORDER-1')
            ->assertStatus(502);

        $this->assertSame('ORDER-1', $reservation->fresh()->payment_id);
    }

    protected function assertFailsWithCredentialsError(callable $call): void
    {
        Log::spy();

        try {
            $call();
            $this->fail('PayPal should refuse to run without credentials.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('PayPal credentials are not configured', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'PayPal credentials are not configured'))
            ->once();
    }
}
