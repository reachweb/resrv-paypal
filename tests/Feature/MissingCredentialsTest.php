<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\Tests\BootedAddonTestCase;
use Reach\StatamicResrv\Facades\Price;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Http\Payment\StripePaymentGateway;

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

    #[Test]
    public function creating_an_order_without_credentials_fails_with_a_clear_logged_error(): void
    {
        Log::spy();

        try {
            (new PaypalPaymentGateway)->paymentIntent(Price::create(55), $this->reservation(['status' => 'pending']), []);
            $this->fail('paymentIntent() should refuse to run without PayPal credentials.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('PayPal credentials are not configured', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'PayPal credentials are not configured'))
            ->once();
    }
}
