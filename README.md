# Resrv PayPal Payment Gateway

PayPal payment gateway add-on for [Statamic Resrv](https://github.com/reachweb/statamic-resrv).

## Requirements

- PHP 8.2+
- Laravel 11.x
- Statamic 5.x
- Statamic Resrv 5.x

## Installation

```bash
composer require reachweb/resrv-payment-paypal
```

Optionally publish the configuration:

```bash
php artisan vendor:publish --tag=resrv-paypal-config
```

## Configuration

### Services Configuration

Add the PayPal configuration to your `config/services.php` file:

```php
'paypal' => [
    'client_id' => env('PAYPAL_CLIENT_ID'),
    'client_secret' => env('PAYPAL_CLIENT_SECRET'),
    'mode' => env('PAYPAL_MODE', 'sandbox'),
    'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
],
```

### Environment Variables

Add the following to your `.env` file:

```env
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
PAYPAL_MODE=sandbox
PAYPAL_WEBHOOK_ID=your_webhook_id
```

> **Important:** `PAYPAL_WEBHOOK_ID` is required. Webhooks are mandatory for this payment gateway.

### Resrv Configuration

Update your `config/resrv-config.php` to use the PayPal gateway:

```php
'payment_gateway' => Reach\ResrvPaymentPaypal\Http\Payment\PaypalPaymentGateway::class,
```

## PayPal Setup

### 1. Create PayPal Developer Account

1. Go to [PayPal Developer Dashboard](https://developer.paypal.com/)
2. Create sandbox and live applications
3. Get your Client ID and Client Secret

### 2. Configure Webhooks (Required)

Webhooks are **mandatory** for this payment gateway. They ensure payment confirmations are received reliably.

1. In your PayPal application settings, add a webhook with your site's webhook URL:

```
https://yoursite.com/!/resrv/webhook
```

2. Subscribe to these events:
   - `PAYMENT.CAPTURE.COMPLETED`
   - `PAYMENT.CAPTURE.DENIED`
   - `PAYMENT.CAPTURE.REFUNDED`

3. Copy the **Webhook ID** to your `PAYPAL_WEBHOOK_ID` environment variable.

The gateway uses PayPal's [verify-webhook-signature API](https://developer.paypal.com/docs/api/webhooks/v1/#verify-webhook-signature) to validate all incoming webhooks, protecting against spoofing attacks.

### 3. Going Live

1. Create a live webhook in your PayPal application
2. Update environment variables with live credentials and webhook ID
3. Set `PAYPAL_MODE=live`

## Payment Flow

1. User initiates checkout in Resrv
2. PayPal Order is created with redirect URLs
3. User is redirected to PayPal to approve payment
4. After approval, user returns to your site
5. Payment is captured automatically
6. PayPal sends webhook notification
7. Webhook signature is verified
8. Reservation is confirmed

## Currency Support

Ensure your Resrv currency (`resrv-config.currency_isoCode`) is [supported by PayPal](https://developer.paypal.com/docs/reports/reference/paypal-supported-currencies/).

## Documentation

For detailed setup instructions, see [docs/paypal.md](docs/paypal.md).

## License

MIT License. See [LICENSE](LICENSE) for details.
