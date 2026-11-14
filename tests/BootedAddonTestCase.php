<?php

namespace Reach\ResrvPaymentPaypal\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use MarcoRieser\Livewire\ServiceProvider;
use Mockery;
use PaypalServerSdkLib\ApiHelper;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\Http\HttpRequest;
use Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway;
use Reach\ResrvPaymentPaypal\PaypalServiceProvider;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\StatamicResrvServiceProvider;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/**
 * The add-on booted through Statamic's addon manifest (so bootAddon() registers the route, the view
 * namespace and the SDK client as on a real site), next to Resrv's models and migrations on SQLite.
 */
abstract class BootedAddonTestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = PaypalServiceProvider::class;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            StatamicResrvServiceProvider::class,
            LivewireServiceProvider::class,
            ServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $this->defineServicesConfig($app);
        $app['config']->set('resrv-config.currency_isoCode', 'EUR');
        $app['config']->set('resrv-config.payment_gateways', [
            'paypal' => ['class' => PaypalPaymentGateway::class],
        ]);
    }

    protected function defineServicesConfig($app): void
    {
        $app['config']->set('services.paypal', [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'mode' => 'sandbox',
            'webhook_id' => 'test_webhook_id',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__).'/vendor/reachweb/statamic-resrv/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->forgetInstance(PaymentGatewayManager::class);
    }

    protected function reservation(array $attributes = []): Reservation
    {
        if (! Collection::find('rooms')) {
            Collection::make('rooms')->save();
        }

        $entry = Entry::make()->collection('rooms')->slug('room')->data(['title' => 'Double room']);
        $entry->save();

        return Reservation::factory()->create(array_merge([
            'item_id' => $entry->id(),
            'status' => 'confirmed',
            'payment' => 50,
            'payment_surcharge' => 5,
            'payment_gateway' => 'paypal',
            'payment_id' => 'CAPTURE-1',
            'date_start' => now()->addDays(10),
            'date_end' => now()->addDays(12),
        ], $attributes));
    }

    /**
     * An HTTP error exactly as paypal-server-sdk 2.x returns it: no exception, an ApiResponse whose
     * isError() is true and whose result is the decoded error body — top level cast to an array,
     * nested objects left as stdClass (Core\Response\Context::getResponseBody()).
     */
    protected function paypalError(int $status, string $name, array $issues = []): ApiResponse
    {
        $body = json_encode([
            'name' => $name,
            'message' => 'PayPal error',
            'debug_id' => 'debug-1',
            'details' => array_map(fn ($issue) => ['issue' => $issue, 'description' => 'PayPal error'], $issues),
        ]);

        return new ApiResponse(Mockery::mock(HttpRequest::class), $status, null, [], (array) json_decode($body), $body);
    }

    /** A 2xx whose result is the real SDK model (e.g. Order, CapturedPayment, Refund), mapped from PayPal JSON. */
    protected function paypalSuccess(string $modelClass, array $json, int $status = 200): ApiResponse
    {
        $body = json_encode($json);
        $model = ApiHelper::getJsonHelper()->mapClass(json_decode($body), $modelClass);

        return new ApiResponse(Mockery::mock(HttpRequest::class), $status, null, [], $model, $body);
    }
}
