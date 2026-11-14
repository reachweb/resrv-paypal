<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Tests\BootedAddonTestCase;

/**
 * A site that configures PayPal in config/services.php (as config/resrv-paypal.php invites) and
 * leaves the PAYPAL_* env vars unset. The add-on config's PAYPAL_MODE default is 'sandbox', so
 * overwriting services.paypal with it put such a site back on sandbox without credentials.
 */
class ServicesConfigTest extends BootedAddonTestCase
{
    // Runs before providers register, as config/services.php is loaded before them on a site.
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('services.paypal', [
            'client_id' => 'services-client-id',
            'client_secret' => 'services-secret',
            'mode' => 'live',
        ]);
    }

    protected function defineServicesConfig($app): void {}

    #[Test]
    public function credentials_set_in_config_services_survive_the_add_on_config(): void
    {
        $this->assertSame('services-client-id', config('services.paypal.client_id'));
        $this->assertSame('services-secret', config('services.paypal.client_secret'));
        $this->assertSame('live', config('services.paypal.mode'));
        // Left unset in services.php, so the add-on config (PAYPAL_WEBHOOK_ID) fills it in.
        $this->assertSame('test_webhook_id', config('services.paypal.webhook_id'));
    }
}
