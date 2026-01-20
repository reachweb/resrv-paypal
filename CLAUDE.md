# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PayPal payment gateway add-on for [Statamic Resrv](https://github.com/reachweb/statamic-resrv), using PayPal JavaScript SDK for inline payments (PayPal buttons + Card Fields).

## Technology Stack

- PHP 8.2+, Laravel 11.x, Statamic 5.x
- `paypal/paypal-server-sdk` v2.1+ (Orders API v2, Payments API v2)
- PayPal JavaScript SDK (buttons + card-fields components)

## Build Commands

```bash
composer install          # Install dependencies
./vendor/bin/pint         # Code formatting
./vendor/bin/phpunit      # Run tests
```

## Architecture

### PaymentInterface Implementation

[PaypalPaymentGateway.php](src/Http/Payment/PaypalPaymentGateway.php) implements `Reach\StatamicResrv\Http\Payment\PaymentInterface`:

- `paymentIntent($payment, $reservation, $data)` - Creates PayPal Order, returns `stdClass` with `id` and `client_secret` (both contain order ID for JS SDK)
- `redirectsForPayment()` - Returns `false` (payment handled inline via JS SDK)
- `handleRedirectBack()` - Verifies payment was captured by checking `payment_id` exists on reservation
- `refund($reservation)` - Refunds via PayPal Payments API using stored capture ID
- `verifyPayment($request)` - Handles webhook events with signature verification, dispatches `ReservationConfirmed` or `ReservationCancelled`

### PayPal Capture Controller

[PaypalCaptureController.php](src/Http/Controllers/PaypalCaptureController.php) handles capture requests from the JS SDK:

- `POST /resrv-paypal/capture/{orderId}` - Validates order belongs to reservation, captures via PayPal API, stores capture ID

### Frontend View

[checkout-payment.blade.php](resources/views/livewire/checkout-payment.blade.php) renders:
- PayPal button (wallet payments)
- Card Fields (direct card input via ACDC)
- Uses Alpine.js for state management

### PayPal SDK Client

[PaypalServiceProvider.php](src/PaypalServiceProvider.php) registers:
- Singleton `PaypalServerSdkClient` configured from `config/services.paypal`
- Views under `statamic-resrv` namespace to override default checkout-payment view

### Payment Flow (JS SDK)

1. `paymentIntent()` creates PayPal Order → returns order ID
2. Checkout page renders PayPal buttons + Card Fields via JS SDK
3. User clicks PayPal button (popup) OR enters card details
4. JS SDK calls `/resrv-paypal/capture/{orderId}` endpoint
5. `PaypalCaptureController` captures the order → stores capture ID as `payment_id`
6. JS redirects to checkout complete page
7. `handleRedirectBack()` verifies `payment_id` exists → returns success
8. PayPal sends webhook notification
9. `verifyPayment()` verifies signature → confirms reservation

### Webhook Signature Verification (Mandatory)

Webhooks are mandatory. [WebhookSignatureVerifier.php](src/Http/Payment/WebhookSignatureVerifier.php):
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
- Card Fields require Advanced Credit and Debit Card Payments (ACDC) enabled on PayPal account
- View registered under `statamic-resrv` namespace to override Resrv's default checkout-payment view
