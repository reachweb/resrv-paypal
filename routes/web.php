<?php

use Illuminate\Support\Facades\Route;

// PayPal webhook route (registered separately to avoid CSRF)
// The webhook is handled by Resrv's built-in webhook controller at resrv.webhook.store
