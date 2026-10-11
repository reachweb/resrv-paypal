<?php

namespace Reach\ResrvPaymentPaypal\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use PHPUnit\Framework\Attributes\Test;
use Reach\ResrvPaymentPaypal\Tests\TestCase;

class CaptureRouteCsrfTest extends TestCase
{
    // Laravel 13's web group runs PreventRequestForgery, Laravel 12's runs ValidateCsrfToken.
    private const CSRF_MIDDLEWARE = [
        PreventRequestForgery::class,
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ];

    // Statamic isn't booted here, so the addon's web routes aren't registered. Load the route file directly.
    protected function defineRoutes($router): void
    {
        $router->group([], __DIR__.'/../../routes/web.php');
    }

    // Laravel skips the CSRF check while running unit tests, so posting to the route can't catch a
    // missing exclusion. Resolve its middleware stack the way the router does instead.
    #[Test]
    public function capture_route_is_excluded_from_csrf_protection(): void
    {
        // The HTTP kernel hands its middleware groups to the router when resolved, as it is on every request.
        $this->app->make(Kernel::class);

        $router = app('router');
        $route = $router->getRoutes()->getByName('resrv-paypal.capture');

        $this->assertNotNull($route, 'Route [resrv-paypal.capture] is not registered.');

        // Without its exclusions the route must run CSRF middleware, otherwise the check below proves nothing.
        $this->assertNotEmpty(
            array_intersect(self::CSRF_MIDDLEWARE, $router->resolveMiddleware($route->gatherMiddleware())),
            'Route [resrv-paypal.capture] is not in a group that runs CSRF middleware.'
        );

        $this->assertSame(
            [],
            array_values(array_intersect(self::CSRF_MIDDLEWARE, $router->gatherRouteMiddleware($route))),
            'Route [resrv-paypal.capture] still runs CSRF middleware.'
        );
    }
}
