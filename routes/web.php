<?php

use Illuminate\Support\Facades\Route;
use Reach\ResrvPaymentPaypal\Http\Controllers\PaypalCaptureController;

// PayPal capture endpoint for JS SDK
Route::post('/resrv-paypal/capture/{orderId}', [PaypalCaptureController::class, 'capture'])
    ->middleware(['web'])
    ->name('resrv-paypal.capture');

// PayPal webhook route (registered separately to avoid CSRF)
// The webhook is handled by Resrv's built-in webhook controller at resrv.webhook.store
