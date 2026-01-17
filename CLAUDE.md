# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PayPal payment gateway add-on for [Statamic Resrv](https://github.com/reachweb/statamic-resrv), following the architecture of [resrv-payment-mollie](https://github.com/reachweb/resrv-payment-mollie).

## Technology Stack

- PHP 8.2+, Laravel 11.x, Statamic 5.x
- `paypal/paypal-server-sdk` v2.1+ (Orders API v2, Payments API v2)

## Build Commands

```bash
composer install          # Install dependencies
./vendor/bin/pint         # Code formatting
./vendor/bin/phpunit      # Run tests
```

## Architecture

### PaymentInterface Implementation

[PaypalPaymentGateway.php](src/Http/Payment/PaypalPaymentGateway.php) implements `Reach\StatamicResrv\Http\Payment\PaymentInterface`:

- `paymentIntent($payment, $reservation, $data)` - Creates PayPal Order with redirect URLs, returns `stdClass` with `id`, `client_secret`, `redirectTo`
- `handleRedirectBack()` - Captures payment when user returns from PayPal, updates reservation with capture ID
- `refund($reservation)` - Refunds via PayPal Payments API using stored capture ID
- `verifyPayment($request)` - Handles webhook events with signature verification, dispatches `ReservationConfirmed` or `ReservationCancelled`

### PayPal SDK Client

[PaypalServiceProvider.php](src/PaypalServiceProvider.php) registers a singleton `PaypalServerSdkClient` configured from `config/services.paypal`.

### Payment Flow

1. `paymentIntent()` creates PayPal Order → returns approval URL
2. User redirected to PayPal → approves payment
3. PayPal redirects to checkout complete URL with `token` param (order ID)
4. `handleRedirectBack()` captures the order → stores capture ID as `payment_id`
5. PayPal sends webhook notification
6. `verifyPayment()` verifies signature via PayPal API → confirms reservation

### Webhook Signature Verification (Mandatory)

Webhooks are mandatory. The `verifyWebhookSignature()` method:
1. Extracts PayPal headers (`PAYPAL-AUTH-ALGO`, `PAYPAL-CERT-URL`, `PAYPAL-TRANSMISSION-ID`, `PAYPAL-TRANSMISSION-SIG`, `PAYPAL-TRANSMISSION-TIME`)
2. Gets access token via OAuth2
3. POSTs to PayPal's `/v1/notifications/verify-webhook-signature` endpoint
4. Validates `verification_status === 'SUCCESS'`

### Webhook Events

- `PAYMENT.CAPTURE.COMPLETED` - Confirms reservation
- `PAYMENT.CAPTURE.DENIED` - Cancels reservation
- `PAYMENT.CAPTURE.REFUNDED` - Handled for refund notifications

## Environment Variables

```
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_MODE=sandbox|live
PAYPAL_WEBHOOK_ID=        # Required - webhook signature verification will fail without this
```

## Key Implementation Notes

- Uses `HandlesStatamicQueries` trait for `getCheckoutCompleteEntry()`
- Amount formatting uses Resrv's `$payment->format()` method (string with 2 decimals)
- Currency from `config('resrv-config.currency_isoCode')`
- Capture ID stored in `reservation.payment_id` for refunds
- Webhook verification uses Laravel's `Http` facade to call PayPal's verify-webhook-signature API
