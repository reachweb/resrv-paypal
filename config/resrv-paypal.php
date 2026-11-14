<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PayPal API Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are loaded from environment variables and used to
    | authenticate with the PayPal API. You can also configure these in
    | config/services.php under the 'paypal' key; values set there take
    | precedence over these.
    |
    */

    'client_id' => env('PAYPAL_CLIENT_ID'),
    'client_secret' => env('PAYPAL_CLIENT_SECRET'),
    'mode' => env('PAYPAL_MODE', 'sandbox'),
    'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
];
