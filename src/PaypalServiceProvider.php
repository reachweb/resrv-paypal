<?php

namespace Reach\ResrvPaymentPaypal;

use Illuminate\Support\Facades\Log;
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

        // A value a site sets under config/services.php 'paypal' wins; the add-on config (the
        // PAYPAL_* env vars) fills in whatever it leaves unset. The other way round, the add-on's
        // 'sandbox' mode default would silently override a 'live' set in services.php.
        $services = $this->app->config->get('services.paypal', []);

        foreach (['client_id', 'client_secret', 'mode', 'webhook_id'] as $key) {
            $services[$key] = $services[$key] ?? config("resrv-paypal.{$key}");
        }

        $this->app->config->set('services.paypal', $services);
    }

    public function bootAddon(): void
    {
        // Register views under own namespace - gateway specifies its view via paymentView()
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'resrv-paypal');

        // Publish config
        $this->publishes([
            __DIR__.'/../config/resrv-paypal.php' => config_path('resrv-paypal.php'),
        ], 'resrv-paypal-config');

        // Publish views for customization
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/resrv-paypal'),
        ], 'resrv-paypal-views');

        $this->app->singleton(PaypalServerSdkClient::class, function () {
            $clientId = config('services.paypal.client_id');
            $clientSecret = config('services.paypal.client_secret');

            // Resolved only when PayPal is called (PaypalApiTrait::client()). The SDK builder
            // would otherwise TypeError on a null id, or send blank credentials to PayPal.
            if (blank($clientId) || blank($clientSecret)) {
                $message = 'PayPal credentials are not configured. Set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET in your .env file, or client_id and client_secret under paypal in config/services.php.';

                Log::error($message);

                throw new \RuntimeException($message);
            }

            return PaypalServerSdkClientBuilder::init()
                ->clientCredentialsAuthCredentials(
                    ClientCredentialsAuthCredentialsBuilder::init($clientId, $clientSecret)
                )
                ->environment(
                    config('services.paypal.mode') === 'live'
                        ? Environment::PRODUCTION
                        : Environment::SANDBOX
                )
                // The SDK default is no timeout. refund() runs inside the REFUNDED status
                // transition's row lock, so a PayPal brownout must not pin the reservation
                // row (and a DB connection) indefinitely.
                ->timeout(15)
                ->build();
        });
    }
}
