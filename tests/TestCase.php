<?php

namespace Reach\ResrvPaymentPaypal\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Reach\ResrvPaymentPaypal\PaypalServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
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
