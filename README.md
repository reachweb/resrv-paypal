# Resrv PayPal Payment Gateway

PayPal payment gateway add-on for [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

## Features

- **PayPal Wallet Payments** - Users can pay with their PayPal account
- **Direct Card Payments** - Credit/debit card fields rendered directly on your checkout page (Advanced Credit and Debit Card Payments)
- **No Redirects** - Payment handled inline via PayPal JavaScript SDK
- **Secure** - PCI-compliant hosted card fields, webhook signature verification

## Requirements

- PHP 8.2+
- Laravel 11.x
- Statamic 5.x
- Statamic Resrv 5.x
- PayPal Business Account with Advanced Credit and Debit Card Payments enabled (for card fields)

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

> **Important:** For security purposes, never commit your API credentials to version control. Always use environment variables.

If you have configuration caching enabled, clear it after updating your `.env` file:

```bash
php artisan config:clear
```

## Webhook Configuration (Required)

Webhooks are **mandatory** for the PayPal payment gateway. They ensure payment confirmations are received even if a customer closes their browser before returning to your site.

### Setting up Webhooks

1. In your PayPal Developer Dashboard, go to your application settings
2. Navigate to **Webhooks** and click **Add Webhook**
3. Enter your webhook URL: `https://yoursite.com/resrv/api/webhook`
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

Update your `config/resrv-config.php` file to use the PayPal payment gateway:

```php
'payment_gateway' => Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway::class,
```

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

This publishes the view to `resources/views/vendor/statamic-resrv/livewire/checkout-payment.blade.php`.

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
