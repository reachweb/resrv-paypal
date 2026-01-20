<?php

use Illuminate\Support\Facades\Route;
use Reach\ResrvPaymentPaypal\Http\Controllers\PaypalCaptureController;

// PayPal capture endpoint for JS SDK (excluded from CSRF - security via order ID verification)
Route::post('/resrv-paypal/capture/{orderId}', [PaypalCaptureController::class, 'capture'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware(['web'])
    ->name('resrv-paypal.capture');
