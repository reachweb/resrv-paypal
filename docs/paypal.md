---
title: PayPal
---

# PayPal

:::caution
Even though this payment gateway is tested, it's suggested that you test the payment gateway in sandbox mode before going live.
:::

## Installation

Install the PayPal addon using Composer:

```bash
composer require reachweb/resrv-payment-paypal
```

## PayPal Account Setup

1. Go to the [PayPal Developer Dashboard](https://developer.paypal.com/)
2. Create a new application (or use an existing one)
3. Note your **Client ID** and **Client Secret** for both sandbox and live environments

## API Configuration

Add your PayPal credentials to your `.env` file:

```env
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
PAYPAL_MODE=sandbox
PAYPAL_WEBHOOK_ID=your_webhook_id
```

Set `PAYPAL_MODE` to `sandbox` for testing or `live` for production.

:::tip
For security purposes, never commit your API credentials to version control. Always use environment variables.
:::

If you have configuration caching enabled, clear it after updating your `.env` file:

```bash
php artisan config:clear
```

## Webhook Configuration (Required)

Webhooks are **mandatory** for the PayPal payment gateway. They ensure payment confirmations are received even if a customer closes their browser before returning to your site.

### Setting up Webhooks

1. In your PayPal Developer Dashboard, go to your application settings
2. Navigate to **Webhooks** and click **Add Webhook**
3. Enter your webhook URL: `https://yoursite.com/!/resrv/webhook`
4. Subscribe to these events:
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.DENIED`
   - `PAYMENT.CAPTURE.REFUNDED`
5. Save the webhook and copy the **Webhook ID**
6. Add the Webhook ID to your `.env` file:

```env
PAYPAL_WEBHOOK_ID=your_webhook_id
```

:::warning
Without a valid `PAYPAL_WEBHOOK_ID`, webhook signature verification will fail and payment confirmations will be rejected with a 403 error.
:::

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

When a customer completes a reservation:

1. They are redirected to PayPal to approve the payment
2. After approval, they return to your checkout completion page
3. The payment is captured automatically
4. PayPal sends a webhook notification
5. The webhook signature is verified
6. The reservation is confirmed

## Going Live

Before accepting live payments:

1. Test thoroughly in sandbox mode with [PayPal sandbox accounts](https://developer.paypal.com/tools/sandbox/)
2. Create a live webhook in your PayPal application with the same events
3. Update your `.env` file with live credentials:

```env
PAYPAL_CLIENT_ID=your_live_client_id
PAYPAL_CLIENT_SECRET=your_live_client_secret
PAYPAL_MODE=live
PAYPAL_WEBHOOK_ID=your_live_webhook_id
```

4. Clear the configuration cache:

```bash
php artisan config:clear
```

## Supported Currencies

Ensure your Resrv currency configuration (`resrv-config.currency_isoCode`) uses a [PayPal-supported currency](https://developer.paypal.com/docs/reports/reference/paypal-supported-currencies/).

## Troubleshooting

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
