<?php

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Reach\ResrvPaymentPaypal\Http\Controllers\PaypalCaptureController;

// PayPal capture endpoint for JS SDK (excluded from CSRF - security via order ID verification)
// Laravel 13's web group runs PreventRequestForgery, Laravel 12's runs a VerifyCsrfToken subclass, so exclude both.
// PreventRequestForgery doesn't exist on Laravel 12, where the router skips unknown classes.
Route::post('/resrv-paypal/capture/{orderId}', [PaypalCaptureController::class, 'capture'])
    ->withoutMiddleware([VerifyCsrfToken::class, PreventRequestForgery::class])
    ->middleware(['web'])
    ->name('resrv-paypal.capture');
