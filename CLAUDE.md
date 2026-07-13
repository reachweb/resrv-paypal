# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PayPal payment gateway add-on for [Statamic Resrv](https://github.com/reachweb/statamic-resrv), using PayPal JavaScript SDK for inline payments (PayPal buttons + Card Fields).

## Technology Stack

- PHP 8.4+, Laravel 12.x/13.x, Statamic 6.x, Statamic Resrv 6.x (multiple payment gateways)
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

- `name()` / `label()` / `paymentView()` / `supportsManualConfirmation()` / `supportsAutomaticRefunds()` - Identity methods for Resrv's multiple-gateway system (`supportsAutomaticRefunds()` returns `true`, enabling customer self-cancellation)
- `paymentIntent($payment, $reservation, $data, $returnUrl = null)` - Creates PayPal Order, returns `stdClass` with `id` and `client_secret` (both contain order ID for JS SDK); the reservation id rides along as `custom_id` for stale-intent webhook reconciliation. `$returnUrl` (optional 4th param, Step 12) is ignored — inline gateway sets its redirect target via the `checkoutCompletedUrl` Livewire property
- `retrievePaymentIntent($paymentId, $reservation)` - Resumes an interrupted payment (used by the manual pay-by-link page) by fetching the PayPal Order; a COMPLETED order is judged by its capture's own status (an interrupted capture can leave the order id stored while the capture inside is DECLINED or PENDING), and if `payment_id` has already flipped to a capture ID it resolves that capture directly — either way a live/settling capture reports succeeded/processing (never minting a duplicate order) and a terminal DECLINED/FAILED capture reports canceled (so the caller replaces it and the customer can retry). Returns `null` only when the intent is genuinely gone; transient API failures propagate
- `cancelPaymentIntent($paymentId, $reservation)` - No-op (un-captured CAPTURE-intent orders have no void endpoint and expire on their own)
- `redirectsForPayment()` - Returns `false` (payment handled inline via JS SDK)
- `handleRedirectBack()` - Re-verifies the stored capture with PayPal to show an accurate immediate result (the webhook remains the source of truth)
- `refund($reservation)` - Full-capture refund (payment + surcharge) via PayPal Payments API with a stable `PayPal-Request-Id` idempotency key; an already-fully-refunded capture reconciles to success; every failure surfaces as `RefundFailedException` (runs inside the REFUNDED transition's row lock)
- `verifyPayment($request)` - Verifies webhook signature first, guards against stale intents / orphaned charges / amount mismatches, then confirms via `transitionTo(CONFIRMED, tolerant: true)` and dispatches `ReservationConfirmed` with `VIA_WEBHOOK` context

### PayPal Capture Controller

[PaypalCaptureController.php](src/Http/Controllers/PaypalCaptureController.php) handles capture requests from the JS SDK:

- `POST /resrv-paypal/capture/{orderId}` - Validates order belongs to reservation, captures via PayPal API, stores capture ID. Authorized by the owning checkout session, or sessionless for pay-by-link manual reservations (`AWAITING_PAYMENT` with an unexpired `hold_expires_at` — mirroring the pay page's deadline guard, since the lapsed-hold sweep can lag). The response branches on the CAPTURE's own status, not the order's (a COMPLETED order can carry a DECLINED or PENDING capture): COMPLETED → success, PENDING (eCheck/risk review) → pending redirect, DECLINED/FAILED → declined with `requiresNewOrder: true` (the order is consumed — the blade re-mints in place via `$wire.pay()` on pay-by-link, or reloads on checkout so the payment step re-mints); the capture ID is stored for all three so the webhook and `retrievePaymentIntent()` key on it

### Frontend View

[checkout-payment.blade.php](resources/views/livewire/checkout-payment.blade.php) renders:
- PayPal button (wallet payments)
- Card Fields (direct card input via ACDC)
- Uses Alpine.js for state management

### PayPal SDK Client

[PaypalServiceProvider.php](src/PaypalServiceProvider.php) registers:
- Singleton `PaypalServerSdkClient` configured from `config/services.paypal`, with a 15s HTTP timeout (refund runs inside a DB row lock; the SDK default is no timeout)
- Views under the `resrv-paypal` namespace; the gateway points Resrv at them via `paymentView()`

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

- `PAYMENT.CAPTURE.COMPLETED` - Confirms reservation (via `transitionTo`, with stale-intent/orphan/amount guards)
- `PAYMENT.CAPTURE.DENIED` / `DECLINED` - Failed attempt is retryable; reservation stays PENDING
- `PAYMENT.CAPTURE.PENDING` - Reservation stays PENDING until a later COMPLETED/DENIED resolves it
- `PAYMENT.CAPTURE.REFUNDED` / `REVERSED` - Acknowledged only; Resrv core owns the refund lifecycle

## Environment Variables

```
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_MODE=sandbox|live
PAYPAL_WEBHOOK_ID=        # Required - webhook signature verification will fail without this
```

## Key Implementation Notes

- Amount formatting uses Resrv's `$payment->format()` method (string with 2 decimals)
- Currency from `config('resrv-config.currency_isoCode')`
- Capture ID stored in `reservation.payment_id` for refunds; refunds send an empty body (full refund of the capture — never a partial `payment`-only amount, which would strand the surcharge)
- Webhook verification uses Laravel's `Http` facade to call PayPal's verify-webhook-signature API
- Card Fields require Advanced Credit and Debit Card Payments (ACDC) enabled on PayPal account
- Register the gateway under `payment_gateways` in `config/resrv-config.php`; the config key doubles as the per-gateway webhook URL segment (`/resrv/api/webhook/paypal`)
