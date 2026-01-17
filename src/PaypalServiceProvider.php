<?php

namespace Reach\ResrvPaymentPaypal;

use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use Statamic\Providers\AddonServiceProvider;

class PaypalServiceProvider extends AddonServiceProvider
{
    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/resrv-paypal.php', 'resrv-paypal');

        $this->app->config->set('services.paypal', [
            'client_id' => config('resrv-paypal.client_id'),
            'client_secret' => config('resrv-paypal.client_secret'),
            'mode' => config('resrv-paypal.mode'),
            'webhook_id' => config('resrv-paypal.webhook_id'),
        ]);
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/resrv-paypal.php' => config_path('resrv-paypal.php'),
        ], 'resrv-paypal-config');

        $this->app->singleton(PaypalServerSdkClient::class, function () {
            return PaypalServerSdkClientBuilder::init()
                ->clientCredentialsAuthCredentials(
                    ClientCredentialsAuthCredentialsBuilder::init(
                        config('services.paypal.client_id'),
                        config('services.paypal.client_secret')
                    )
                )
                ->environment(
                    config('services.paypal.mode') === 'live'
                        ? Environment::PRODUCTION
                        : Environment::SANDBOX
                )
                ->build();
        });
    }
}
