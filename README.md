# Resrv PayPal Payment Gateway

PayPal payment gateway add-on for [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

## Features

- **PayPal Wallet Payments** - Users can pay with their PayPal account
- **Direct Card Payments** - Credit/debit card fields rendered directly on your checkout page (Advanced Credit and Debit Card Payments)
- **No Redirects** - Payment handled inline via PayPal JavaScript SDK
- **Secure** - PCI-compliant hosted card fields, webhook signature verification

## Requirements

- PHP 8.4+
- Laravel 12.x or 13.x
- Statamic 6.x
- Statamic Resrv 6.5 or later
- PayPal Business Account with Advanced Credit and Debit Card Payments enabled (for card fields)

> **Upgrading from Resrv 5?** This release targets Resrv's v6 multiple-payment-gateway system
> (`PaymentInterface` now requires `name()`, `label()`, `paymentView()`, `supportsManualConfirmation()`,
> `cancelPaymentIntent()` and `supportsAutomaticRefunds()`). Tagged releases start at v1.0.0 and need
> Resrv 6.5 or later; there is no release for Resrv 5, so a Resrv 5 site has to stay on the last
> compatible commit: `composer require reachweb/resrv-payment-paypal:dev-main#049bfa9`.

## Upgrading from 1.0.1

1.0.1 never requested 3-D Secure for card-field payments: it passed a Hosted Fields option that
Card Fields ignore, so every card payment was captured without authenticating the cardholder.
Card payments now request 3-D Secure (see [3-D Secure](#3-d-secure-card-payments)). What changes
for a site:

- **Published payment view:** if you ran `vendor:publish --tag=resrv-paypal-views`, re-publish it
  (`php artisan vendor:publish --tag=resrv-paypal-views --force`) and re-apply your edits. The old
  copy keeps paying cards without 3-D Secure.
- **Failed authentications are refused:** a card whose cardholder fails or cannot complete the
  bank's check is not charged; the customer sees an error and can retry or pay with PayPal.
- **New optional setting:** `PAYPAL_SCA_METHOD` (default `SCA_WHEN_REQUIRED`).

## Upgrading from 1.0.0

v1.0.0 could not take payments: the payment view failed to render, and a refund PayPal rejected
was still recorded as refunded. Upgrade with `composer update reachweb/resrv-payment-paypal`.
What changes for a site:

- **Published payment view:** if you ran `vendor:publish --tag=resrv-paypal-views`, re-publish it
  (`php artisan vendor:publish --tag=resrv-paypal-views --force`) and re-apply your edits. The old
  copy fails to render, and it returns customers to the checkout-complete page with a parameter
  1.0.1 no longer accepts (see [Customizing the Payment View](#customizing-the-payment-view)).
- **Refunds PayPal rejects now fail:** the Control Panel and customer self-cancellation show Resrv's
  refund-failed message and the reservation stays confirmed, instead of being marked refunded with
  no money returned.
- **`config/services.php` is honoured:** values under its `paypal` key now take precedence over the
  `PAYPAL_*` environment variables (see [API Configuration](#api-configuration)).
- **Cancelled or paid-in-person pay-by-link bookings** no longer keep a "payment unresolved" PayPal
  order or send admins an "open payment intent could not be cancelled" alert.

## Installation

```bash
composer require reachweb/resrv-payment-paypal
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=resrv-paypal-config
```

## PayPal Account Setup

1. Go to the [PayPal Developer Dashboard](https://developer.paypal.com/)
2. Create a new application (or use an existing one)
3. Note your **Client ID** and **Client Secret** for both sandbox and live environments
4. Enable **Advanced Credit and Debit Card Payments** in your app settings to allow direct card input

> **Tip:** Advanced Credit and Debit Card Payments (ACDC) is automatically enabled for sandbox accounts. For live accounts, you may need to request access through your PayPal account manager.

## API Configuration

Add your PayPal credentials to your `.env` file:

```env
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
PAYPAL_MODE=sandbox
PAYPAL_WEBHOOK_ID=your_webhook_id
```

Set `PAYPAL_MODE` to `sandbox` for testing or `live` for production.

You can configure the same four values under a `paypal` key in `config/services.php` instead
(`client_id`, `client_secret`, `mode`, `webhook_id`). A value set there takes precedence; the
`PAYPAL_*` environment variables fill in any it leaves out.

> **Important:** For security purposes, never commit your API credentials to version control. Always use environment variables.

If you have configuration caching enabled, clear it after updating your `.env` file:

```bash
php artisan config:clear
```

### 3-D Secure (card payments)

Card-field payments request 3-D Secure (Strong Customer Authentication) from PayPal. Choose when
the cardholder's bank is asked to authenticate them:

```env
PAYPAL_SCA_METHOD=SCA_WHEN_REQUIRED
```

- `SCA_WHEN_REQUIRED` (default): only when a regional mandate such as PSD2 requires it, for
  example an EU merchant charging an EU-issued card. Other card payments go through without a
  challenge, and the chargeback liability stays with you.
- `SCA_ALWAYS`: every card payment the card and its bank support. More payments shift liability to
  the card issuer, at the cost of a challenge step for more customers.

The setting lives in `config/resrv-paypal.php` (`sca_method`). PayPal wallet payments are not
affected: PayPal authenticates those itself.

When PayPal reports the authentication result, the gateway follows
[PayPal's recommended actions](https://developer.paypal.com/docs/checkout/advanced/customize/3d-secure/response-parameters/):
the card is charged when liability shifted (`POSSIBLE`), when the card or its bank does not take
part in 3-D Secure, or when no authentication was needed. It is **not** charged when the
cardholder failed or could not complete authentication (`liability_shift` `NO` on an enrolled
card) or when the authentication system was unavailable (`UNKNOWN`); the customer sees an error
and can try again or pay with PayPal.

To test in sandbox, use PayPal's
[3-D Secure test cards](https://developer.paypal.com/docs/checkout/advanced/customize/3d-secure/test/).
The US cards may not be challenged under `SCA_WHEN_REQUIRED` (no mandate applies to them), so set
`PAYPAL_SCA_METHOD=SCA_ALWAYS` while testing, or use the cards for your own region.

## Webhook Configuration (Required)

Webhooks are **mandatory** for the PayPal payment gateway. They ensure payment confirmations are received even if a customer closes their browser before returning to your site.

### Setting up Webhooks

1. In your PayPal Developer Dashboard, go to your application settings
2. Navigate to **Webhooks** and click **Add Webhook**
3. Enter your webhook URL:
   - If you registered PayPal under `payment_gateways` (multiple gateways), use the **per-gateway**
     route whose segment matches your config key: `https://yoursite.com/resrv/api/webhook/paypal`
   - If PayPal is your single gateway (legacy `payment_gateway` key), the bare
     `https://yoursite.com/resrv/api/webhook` route also works
4. Subscribe to these events:
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.DENIED`
   - `PAYMENT.CAPTURE.REFUNDED`
5. Save the webhook and copy the **Webhook ID**
6. Add the Webhook ID to your `.env` file:

```env
PAYPAL_WEBHOOK_ID=your_webhook_id
```

> **Warning:** Without a valid `PAYPAL_WEBHOOK_ID`, webhook signature verification will fail and payment confirmations will be rejected with a 403 error.

### Webhook Signature Verification

The gateway uses PayPal's [verify-webhook-signature API](https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature) to validate all incoming webhook notifications. This ensures:

- Webhooks actually come from PayPal
- The payload hasn't been tampered with
- Your application is protected from spoofing attacks

## Payment Gateway Configuration

Register the gateway in your `config/resrv-config.php`. Resrv 6 supports multiple gateways, so the
recommended approach is to add PayPal to the `payment_gateways` array (the array key — `paypal` here —
is stored on each reservation and used in the per-gateway webhook URL, so keep it lowercase, stable,
and never change it once reservations exist):

```php
'payment_gateways' => [
    'paypal' => [
        'class' => Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway::class,
        'label' => 'PayPal',
        // Optional, handled entirely by Resrv core (no gateway code changes needed):
        // 'surcharge'     => ['type' => 'percent', 'amount' => 3],
        // 'amount_limits' => ['min' => 5, 'max' => 2000],
    ],
    // 'stripe' => [
    //     'class' => Reach\StatamicResrv\Http\Payment\StripePaymentGateway::class,
    // ],
],
```

If PayPal is your only gateway you can instead use the legacy singular key:

```php
'payment_gateway' => Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway::class,
```

## Refunds & Customer Cancellations

Refunds (from the Control Panel or via Resrv's customer self-cancellation feature) always return
the **full captured amount** — the reservation payment plus any gateway surcharge, exactly what the
customer was charged. The refund call is idempotent (a stable `PayPal-Request-Id` per reservation
and capture), so a retry after a network failure never double-refunds; and if the capture turns out
to be already fully refunded (e.g. refunded manually in the PayPal dashboard), the reservation is
still marked refunded instead of getting stuck.

If PayPal refuses the refund (for example an insufficient PayPal balance, wrong credentials, or a
PayPal outage), the refund fails: the Control Panel or the customer sees Resrv's refund-failed
message, the reservation stays confirmed, and the PayPal error is logged. Nothing is marked refunded
until PayPal accepts the refund.

Because the gateway reports `supportsAutomaticRefunds() === true`, customers may cancel eligible
reservations themselves from the reservation status page when you enable Resrv's customer
cancellation feature.

## Payment Flow

This gateway uses the PayPal JavaScript SDK for an inline payment experience. When a customer completes a reservation:

1. The checkout page displays PayPal buttons and card fields inline
2. Customer can either:
   - Click the **PayPal button** to pay via PayPal wallet (opens popup)
   - Enter card details directly in the **card fields** on your page
3. Payment is captured via your server
4. Customer is redirected to the checkout completion page
5. PayPal sends a webhook notification
6. The webhook signature is verified
7. The reservation is confirmed

### Payment Options Displayed

- **PayPal Button** - Opens PayPal popup for wallet payments, Pay Later, Venmo (US)
- **Card Fields** - Direct card number, expiry, CVV, and cardholder name input (requires ACDC enabled)

If Advanced Credit and Debit Card Payments is not enabled for your account, only the PayPal button will be displayed.

## Customizing the Payment View

The package provides a default checkout payment view. To customize the styling or layout:

```bash
php artisan vendor:publish --tag=resrv-paypal-views
```

This publishes the view to `resources/views/vendor/resrv-paypal/livewire/checkout-payment.blade.php`.

When you customize it, keep the script's contract with the server:

- The card fields' `createOrder` must get its order from `POST /resrv-paypal/order/{orderId}/card`
  (with the current order id), which creates the card order that carries the 3-D Secure request.
  Returning the order the page was mounted with pays cards without 3-D Secure. After a card
  attempt, the PayPal button gets a wallet order back from `POST /resrv-paypal/order/{orderId}/paypal`.
- After a successful capture, return the customer to `checkoutCompletedUrl` with the
  `capture_id` from the capture response (plus `resrv_gateway` and `redirect_status`). The
  checkout-complete page looks the reservation up by that capture id; it does not accept a
  reservation id.
- Blade compiles every `{{ ... }}` in the file, including ones inside JavaScript comments, so each
  must be a valid PHP expression; anything else (such as an Antlers tag name) makes the whole view
  fail to render. Write `@{{ ... }}` when you need literal braces.

You can customize:
- Button colors and styles
- Card field container styling
- Labels and translations
- Layout and spacing

## Going Live

Before accepting live payments:

1. Test thoroughly in sandbox mode with [PayPal sandbox accounts](https://developer.paypal.com/tools/sandbox/)
2. Create a live webhook in your PayPal application with the same events
3. Ensure Advanced Credit and Debit Card Payments is enabled for your live app
4. Update your `.env` file with live credentials:

```env
PAYPAL_CLIENT_ID=your_live_client_id
PAYPAL_CLIENT_SECRET=your_live_client_secret
PAYPAL_MODE=live
PAYPAL_WEBHOOK_ID=your_live_webhook_id
```

5. Clear the configuration cache:

```bash
php artisan config:clear
```

## Supported Currencies

Ensure your Resrv currency configuration (`resrv-config.currency_isoCode`) uses a [PayPal-supported currency](https://developer.paypal.com/docs/reports/reference/paypal-supported-currencies/).

## Troubleshooting

### Card fields not appearing

If only the PayPal button appears without card fields:
- Verify Advanced Credit and Debit Card Payments (ACDC) is enabled in your PayPal app settings
- Check browser console for JavaScript errors
- Ensure your PayPal Client ID is correct

### Payments not being captured

Ensure that:
- Your PayPal API credentials are correct
- The `PAYPAL_MODE` matches your credential type (sandbox/live)
- Your server can make outbound HTTPS requests to PayPal's API

### Webhook returns 403 error

This indicates webhook signature verification failed. Check that:
- `PAYPAL_WEBHOOK_ID` is correctly set in your `.env` file
- The webhook ID matches the webhook configured in your PayPal application
- Your server time is synchronized (significant time drift can cause verification failures)

### Webhook notifications not received

Verify that:
- Your webhook URL is publicly accessible
- Your server is not blocking PayPal's IP addresses
- The webhook is enabled in your PayPal Developer Dashboard
- You've subscribed to the correct events

### "PayPal webhook ID is not configured" error

You must set the `PAYPAL_WEBHOOK_ID` environment variable. Webhooks are mandatory for this payment gateway to function properly.

### JavaScript SDK fails to load

If you see "Failed to load PayPal SDK" error:
- Check browser console for specific error messages
- Verify your `PAYPAL_CLIENT_ID` is correct
- Ensure your domain is not blocked by PayPal
- Check for Content Security Policy issues

## License

MIT License. See [LICENSE](LICENSE) for details.
