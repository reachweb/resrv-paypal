<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Livewire\Component;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use MarcoRieser\Livewire\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\PaypalServiceProvider;
use Statamic\Testing\AddonTestCase;

/**
 * Renders the gateway's payment view the way Resrv does: inside a Livewire component exposing the
 * properties both payment surfaces provide (CheckoutPayment for checkout, ReservationPayment for
 * pay-by-link). Boots the add-on through Statamic's addon manifest, so the view namespace comes
 * from bootAddon() exactly as on a real site.
 */
class PaymentViewRenderTest extends AddonTestCase
{
    protected string $addonServiceProvider = PaypalServiceProvider::class;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            LivewireServiceProvider::class,
            ServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('services.paypal.mode', 'sandbox');
        $app['config']->set('resrv-config.currency_isoCode', 'EUR');
        $app['config']->set('resrv-config.currency_symbol', '€');
    }

    #[Test]
    public function it_renders_on_the_checkout_surface(): void
    {
        Livewire::test(CheckoutSurfaceStub::class)
            ->assertSeeHtml('id="paypal-button-container"')
            ->assertSeeHtml('id="card-number-field-container"')
            ->assertSeeHtml('currency=EUR')
            ->assertSeeHtml('€ 316.80')
            ->assertSeeHtml('canRemintInline: false');
    }

    #[Test]
    public function it_shows_a_zero_decimal_currency_without_decimals(): void
    {
        config(['resrv-config.currency_isoCode' => 'JPY', 'resrv-config.currency_symbol' => '¥']);

        // The yen has no minor unit: a fixed two-decimal format would print "¥ 3,168.00".
        Livewire::test(CheckoutSurfaceStub::class, ['amount' => 3168.0])
            ->assertSeeHtml('¥ 3168</span>');
    }

    #[Test]
    public function it_renders_on_the_pay_by_link_surface(): void
    {
        Livewire::test(PayByLinkSurfaceStub::class)
            ->assertSeeHtml('id="paypal-button-container"')
            ->assertSeeHtml('canRemintInline: true');
    }

    #[Test]
    public function the_card_fields_pay_on_a_card_order_that_requests_3ds(): void
    {
        $checkout = $this->script(CheckoutSurfaceStub::class);

        // Handing the card fields the mounted wallet order is how card payments ran without
        // 3-D Secure: the request rides only on the order the replace route creates.
        $this->assertStringContainsString("createOrder: () => this.replaceOrder('card')", $checkout);
        $this->assertStringContainsString('fetch(`/resrv-paypal/order/${this.orderId}/${source}`', $checkout);
        $this->assertStringNotContainsString('contingencies', $checkout);

        // Checkout mounts a fresh wallet order the PayPal button pays as is; pay-by-link may resume
        // a card order, which the button must swap for a wallet order first.
        $this->assertStringContainsString("orderSource: 'paypal',", $checkout);
        $this->assertStringContainsString('orderSource: null,', $this->script(PayByLinkSurfaceStub::class));
    }

    /** The view's Alpine script, which Livewire ships in the component's effects. */
    protected function script(string $component): string
    {
        return implode("\n", Livewire::test($component)->effects['scripts']);
    }
}

class CheckoutSurfaceStub extends Component
{
    public string $clientSecret = 'ORDER-1';

    public string $publicKey = 'client-id';

    // Both payment surfaces pass the amount as a float, which drops the trailing zero (316.8).
    public float $amount = 316.80;

    public string $checkoutCompletedUrl = 'https://example.com/checkout-complete';

    public function render()
    {
        return view((new PaypalPaymentGateway)->paymentView());
    }
}

class PayByLinkSurfaceStub extends CheckoutSurfaceStub
{
    public string $checkoutCompletedUrl = 'https://example.com/pay?ref=ABC&hash=123';

    public function pay(): void {}
}
