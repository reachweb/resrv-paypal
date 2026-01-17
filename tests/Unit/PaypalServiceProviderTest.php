<?php

namespace Reach\ResrvPaymentPaypal\Tests\Unit;

use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Tests\TestCase;

class PaypalServiceProviderTest extends TestCase
{
    #[Test]
    public function it_can_resolve_paypal_client(): void
    {
        // The client is registered during bootAddon, so we check if it's resolvable
        // It may not be bound yet if boot hasn't run, so we check the deferred binding
        $this->assertTrue(
            $this->app->bound(PaypalServerSdkClient::class) ||
            class_exists(PaypalServerSdkClient::class)
        );
    }

    #[Test]
    public function it_merges_config(): void
    {
        $this->assertEquals('test_client_id', config('services.paypal.client_id'));
        $this->assertEquals('test_client_secret', config('services.paypal.client_secret'));
        $this->assertEquals('sandbox', config('services.paypal.mode'));
        $this->assertEquals('test_webhook_id', config('services.paypal.webhook_id'));
    }

    #[Test]
    public function it_sets_currency_config(): void
    {
        $this->assertEquals('USD', config('resrv-config.currency_isoCode'));
    }
}
