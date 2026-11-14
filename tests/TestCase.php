<?php

namespace Reach\ResrvPaymentPaypal\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Reach\ResrvPaymentPaypal\PaypalServiceProvider;
use Statamic\Statamic;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Registering the add-on's AddonServiceProvider queues Statamic::booted() callbacks, but
        // Statamic never boots in these tests, so they would pile up statically and run — bound to
        // this dead application — in the next Statamic-booted test (BootedAddonTestCase).
        (new \ReflectionProperty(Statamic::class, 'bootedCallbacks'))->setValue(null, []);
    }

    protected function getPackageProviders($app): array
    {
        return [
            PaypalServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('services.paypal', [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'mode' => 'sandbox',
            'webhook_id' => 'test_webhook_id',
        ]);

        $app['config']->set('resrv-config.currency_isoCode', 'USD');
    }
}
