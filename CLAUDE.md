# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PayPal payment gateway add-on for [Statamic Resrv](https://github.com/reachweb/statamic-resrv), using PayPal JavaScript SDK for inline payments (PayPal buttons + Card Fields).

## Technology Stack

- PHP 8.4+, Laravel 12.x/13.x, Statamic 6.x, Statamic Resrv 6.5+ (multiple payment gateways)
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
- `paymentIntent($payment, $reservation, $data, $returnUrl = null)` - Creates the wallet PayPal Order (no payment source; see `createOrder()`), returns `stdClass` with `id` and `client_secret` (both contain order ID for JS SDK); the reservation id rides along as `custom_id` for stale-intent webhook reconciliation. `$returnUrl` (optional 4th param, Step 12) is ignored — inline gateway sets its redirect target via the `checkoutCompletedUrl` Livewire property
- `retrievePaymentIntent($paymentId, $reservation)` - Resumes an interrupted payment (used by the manual pay-by-link page) by fetching the PayPal Order; a COMPLETED order is judged by its capture's own status (an interrupted capture can leave the order id stored while the capture inside is DECLINED or PENDING), and if the order lookup 404s (`payment_id` has flipped to a capture ID, or the order expired) it resolves that capture directly — either way a live/settling capture reports succeeded/processing (never minting a duplicate order) and a terminal DECLINED/FAILED capture reports canceled (so the caller replaces it and the customer can retry). An un-captured order reports payable only while `orderCanStillBeCaptured()` (reservation PENDING or AWAITING_PAYMENT), otherwise canceled. That includes a card order: reporting it canceled would let a concurrent `pay()` write a new order over a capture landing on it; instead the pay-by-link view starts with an unknown order source, so its PayPal button swaps a resumed order for a wallet order. Returns `null` only when both lookups 404; every other PayPal error throws `PaypalApiException`
- `createOrder($reservation, $amount, $cardReturnUrl = null)` - Creates an order and returns its id. With a return URL it is a card order: `payment_source.card.attributes.verification.method` from `resrv-paypal.sca_method` (`PAYPAL_SCA_METHOD`, default `SCA_WHEN_REQUIRED`) plus the card `experience_context` return/cancel URLs, and `reference_id` `card-{id}` (a wallet order's is `{id}`), which `isCardOrder()` reads back. A blank or unknown `sca_method` falls back to `SCA_WHEN_REQUIRED` with a log warning (a blank env var reads as `''`, and `AVS_CVV` would switch 3-D Secure off). Card Fields read 3-D Secure only from the create-order body (`submit()` takes no contingencies option), and a wallet order must never carry a card payment source
- `cancelPaymentIntent($paymentId, $reservation)` - No-op at PayPal (CAPTURE-intent orders have no void endpoint). The capture route is the void: it refuses an order once Resrv clears `payment_id` or the reservation leaves a payable state, under the row lock
- `redirectsForPayment()` - Returns `false` (payment handled inline via JS SDK)
- `handleRedirectBack()` - Resolves the reservation by the `capture_id` query parameter (never the guessable reservation id) and re-verifies that capture with PayPal to show an accurate immediate result (the webhook remains the source of truth)
- `refund($reservation)` - Full-capture refund (payment + surcharge) via PayPal Payments API with a stable `PayPal-Request-Id` idempotency key; an already-fully-refunded capture (`CAPTURE_FULLY_REFUNDED`) reconciles to success; every PayPal error response, transport failure, or FAILED/CANCELLED refund surfaces as `RefundFailedException` (runs inside the REFUNDED transition's row lock)
- `verifyPayment($request)` - Verifies webhook signature first, guards against stale intents / orphaned charges / amount mismatches (including a COMPLETED capture found through `custom_id` on an already-confirmed reservation: another payment paid it, e.g. a capture that timed out and completed after the customer paid a replacement order, so admins get `OrphanedPaymentNotification`), then confirms via `transitionTo(CONFIRMED, tolerant: true)` and dispatches `ReservationConfirmed` with `VIA_WEBHOOK` context. A reservation found through the `custom_id` fallback gets the capture ID stored as `payment_id` in the same write as CONFIRMED

### SDK responses

`paypal-server-sdk` 2.x does not throw on HTTP errors: it returns an `ApiResponse` whose `isError()` is true and whose result is the decoded error body (top level an array, nested objects `stdClass`). Every SDK call goes through `PaypalApiTrait::paypalResult()`, which throws `PaypalApiException` (status code, `details[].issue` codes, debug id) for those. Tests must model errors the same way (`BootedAddonTestCase::paypalError()`), never with `andThrow()`; transport failures (timeouts) are the only thing the SDK throws

### PayPal Capture Controller

[PaypalCaptureController.php](src/Http/Controllers/PaypalCaptureController.php) handles the JS SDK's capture and order-replacement requests:

Both routes are authorised by `mayPay()`: the order id must be the reservation's `payment_id` on a reservation whose gateway resolves to `PaypalPaymentGateway` (another gateway's intent id is refused), and the reservation must be PENDING and owned by the checkout session, or sessionless for pay-by-link manual reservations (`AWAITING_PAYMENT` with an unexpired `hold_expires_at` — mirroring the pay page's deadline guard, since the lapsed-hold sweep can lag); any other status is refused. Each checks this once without a lock, makes the PayPal calls that need no lock, then re-checks under the reservation's row lock (`underLock()`) before any write.

- `POST /resrv-paypal/order/{orderId}/{card|paypal}` - Replaces the reservation's order with a fresh one for the funding source about to pay (a card order on every card-fields submit, a wallet order when the PayPal button opens on anything but a wallet order). The new order is created before the lock; under the lock the replaced order is read: COMPLETED (a capture whose response was lost) → stores and reports that capture exactly like the capture route instead of letting the customer pay twice (the JS then finishes as after a capture); only CREATED/SAVED/APPROVED/PAYER_ACTION_REQUIRED is replaced; a 404 (a stored capture id) → 502, anything else → 403. The new order becomes `payment_id`, so the replaced order can no longer be captured. Amount is payment + surcharge
- `POST /resrv-paypal/capture/{orderId}` - Reads the order before the lock. COMPLETED (an earlier attempt captured) → reports that capture without capturing again. Otherwise refuses, leaving the order un-captured: a card order that is neither CREATED nor APPROVED, or an order without a card that is not APPROVED (400 declined — e.g. a card order still waiting on its challenge, PAYER_ACTION_REQUIRED, which has no 3-D Secure result yet; a card order reads CREATED, never APPROVED, once the card fields approve it; a CREATED order without a card was never approved, and since the read is taken before the lock, a card confirmed on it afterwards would be captured unchecked); a card payment on an order `isCardOrder()` did not make (400 `Card authentication failed` — the order requested no 3-D Secure, e.g. an outdated published view); a card order whose 3-D Secure result PayPal says not to authorize: `liability_shift` UNKNOWN, or NO with `enrollment_status` Y (400 `Card authentication failed`). NO with N/U/B, POSSIBLE, or a wallet payment proceeds; no authentication result proceeds under `SCA_WHEN_REQUIRED` and is refused (400 `Card authentication failed`) under `SCA_ALWAYS`, since nothing shows 3-D Secure ran (`PaypalPaymentGateway::scaMethod()` is the one reading of the setting). Then captures under the row lock via PayPal API (`Prefer: return=representation` — PayPal documents the default minimal response as id, status and links only) and stores the capture ID, so a concurrent transition either commits first (capture refused) or sees the stored capture. The response branches on the CAPTURE's own status, not the order's (a COMPLETED order can carry a DECLINED or PENDING capture): COMPLETED → success, PENDING (eCheck/risk review) → pending redirect, DECLINED/FAILED → declined with `requiresNewOrder: true` (the order is consumed — the blade re-mints in place via `$wire.pay()` on pay-by-link, or reloads on checkout so the payment step re-mints); the capture ID is stored for all three so the webhook and `retrievePaymentIntent()` key on it. A 422 from PayPal (e.g. `INSTRUMENT_DECLINED`) → 400 declined; `ORDER_ALREADY_CAPTURED` (a retry after a lost response) → reads the order and reports its capture; other errors and transport failures → 502 with a generic message

### Frontend View

[checkout-payment.blade.php](resources/views/livewire/checkout-payment.blade.php) renders:
- PayPal button (wallet payments)
- Card Fields (direct card input via ACDC)
- Uses Alpine.js for state management
- Blade compiles every `{{ }}` in the file, including inside JS comments — never write a non-PHP name (such as an Antlers tag) in double braces there. `tests/Feature/PaymentViewRenderTest.php` renders the view inside Livewire to catch this

### PayPal SDK Client

[PaypalServiceProvider.php](src/PaypalServiceProvider.php) registers:
- `services.paypal` from `config/services.php` where set, falling back per key to `config/resrv-paypal.php` (the `PAYPAL_*` env vars)
- Singleton `PaypalServerSdkClient` configured from `config/services.paypal`, with a 15s HTTP timeout (refund, capture, and the order replacement's read of the order it replaces run inside a DB row lock; the SDK default is no timeout)
- The gateway and capture controller resolve the client at the point of use through `PaypalApiTrait::client()`, never in a constructor: Resrv builds every registered gateway to list them at checkout, so PayPal without credentials must still construct. Resolving the client without a client id or secret logs and throws a `RuntimeException` ("PayPal credentials are not configured")
- Views under the `resrv-paypal` namespace; the gateway points Resrv at them via `paymentView()`

### Payment Flow (JS SDK)

1. `paymentIntent()` creates the wallet PayPal Order → returns order ID
2. Checkout page renders PayPal buttons + Card Fields via JS SDK
3. User clicks PayPal button (popup, pays the wallet order) OR submits card details (Card Fields' `createOrder` swaps in a card order via `/resrv-paypal/order/{orderId}/card`; PayPal runs 3-D Secure; `onApprove` stops on `liabilityShift` UNKNOWN)
4. JS SDK calls `/resrv-paypal/capture/{orderId}` endpoint
5. `PaypalCaptureController` captures the order → stores capture ID as `payment_id`
6. JS redirects to checkout complete page with `capture_id`
7. `handleRedirectBack()` finds the reservation by that capture ID and re-verifies the capture → returns success
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
PAYPAL_SCA_METHOD=        # Optional - SCA_WHEN_REQUIRED (default; also used when blank or unknown) or SCA_ALWAYS, for card payments
```

## Key Implementation Notes

- Amount formatting uses Resrv's `$payment->format()` method (string with 2 decimals)
- Currency from `config('resrv-config.currency_isoCode')`
- Capture ID stored in `reservation.payment_id` for refunds; refunds send an empty body (full refund of the capture — never a partial `payment`-only amount, which would strand the surcharge)
- Webhook verification uses Laravel's `Http` facade to call PayPal's verify-webhook-signature API
- Card Fields require Advanced Credit and Debit Card Payments (ACDC) enabled on PayPal account
- Register the gateway under `payment_gateways` in `config/resrv-config.php`; the config key doubles as the per-gateway webhook URL segment (`/resrv/api/webhook/paypal`)
