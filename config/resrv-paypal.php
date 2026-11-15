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

    /*
    |--------------------------------------------------------------------------
    | 3-D Secure for card payments
    |--------------------------------------------------------------------------
    |
    | The verification method PayPal applies to card-field payments:
    | 'SCA_WHEN_REQUIRED' authenticates the cardholder when a regional mandate
    | such as PSD2 requires it, 'SCA_ALWAYS' authenticates every card payment
    | the card and issuer support. PayPal wallet payments are not affected.
    |
    */

    'sca_method' => env('PAYPAL_SCA_METHOD', 'SCA_WHEN_REQUIRED'),
];
