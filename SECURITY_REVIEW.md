# Security Review: PayPal Payment Gateway for Statamic Resrv

**Review Date:** January 2026
**Reviewed By:** Claude (Opus 4.5)
**Scope:** Full codebase security analysis
**Status:** ✅ All critical issues have been fixed

---

## Executive Summary

This security review identified **3 critical**, **3 medium**, and **4 low** severity issues in the PayPal payment gateway implementation. The most significant concerns involved the order of operations in webhook verification and authorization bypass vulnerabilities in the redirect flow.

**All 3 critical issues have been remediated.** The fixes include:
1. Moving webhook signature verification before any database operations
2. Validating order `reference_id` matches the reservation ID
3. Storing and validating `pending_order_id` to prevent token substitution attacks

---

## Critical Security Issues

### 1. Race Condition in Webhook Verification Order of Operations ✅ FIXED

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:212-268`
**Severity:** 🔴 Critical
**CVSS Estimate:** 7.5 (High)
**Status:** ✅ Fixed - Signature verification now happens first before any database operations

**Description:**
The `verifyPayment()` method performs database lookups and business logic checks **before** verifying the webhook signature. This violates the security principle that authentication/verification must occur before any processing.

**Current vulnerable flow:**
```php
$data = json_decode($payload, true);           // Parse attacker-controlled payload
$captureId = $data['resource']['id'];          // Extract data
$reservation = Reservation::findByPaymentId(); // Database query with attacker input
if ($reservation->status === CONFIRMED) {...}  // Business logic
$isValid = $this->verifyWebhookSignature();    // Signature verified TOO LATE
```

**Attack Scenarios:**
1. Attacker sends forged webhooks to enumerate valid capture IDs
2. Timing attacks to determine reservation states
3. Database query injection if `findByPaymentId()` is vulnerable

**Recommended Fix:**
```php
public function verifyPayment($request)
{
    $payload = $request->getContent();
    $data = json_decode($payload, true);

    if (!$data) {
        Log::warning('PayPal webhook: Invalid JSON payload');
        abort(403);
    }

    // VERIFY SIGNATURE FIRST - before any other processing
    try {
        $isValid = $this->verifyWebhookSignature($request, $payload);
        if (!$isValid) {
            Log::warning('PayPal webhook: Invalid signature');
            abort(403);
        }
    } catch (\Exception $e) {
        Log::error('PayPal webhook signature verification failed: ' . $e->getMessage());
        abort(403);
    }

    // Only proceed with processing after signature is verified
    $eventType = $data['event_type'] ?? null;
    // ... rest of processing
}
```

---

### 2. Insecure Direct Object Reference (IDOR) in handleRedirectBack() ✅ FIXED

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:155-205`
**Severity:** 🔴 Critical
**CVSS Estimate:** 8.1 (High)
**Status:** ✅ Fixed - Order reference_id is now validated to match the reservation ID

**Description:**
The `handleRedirectBack()` method accepts a user-controlled `id` parameter and uses it to look up a reservation without any authorization checks.

**Vulnerable code:**
```php
$id = request()->input('id');           // Attacker controls this
$token = request()->input('token');     // PayPal order ID
$reservation = Reservation::findOrFail($id);  // No auth check!
// Captures payment and returns reservation data
```

**Attack Scenarios:**
1. Attacker guesses/enumerates reservation IDs
2. Attacker captures their payment against another user's reservation
3. Attacker accesses sensitive reservation data from response

**Recommended Fix:**
After capturing the order, validate that the order's `reference_id` matches the reservation:

```php
public function handleRedirectBack(): array
{
    $id = request()->input('id');
    $token = request()->input('token');
    $cancelled = request()->input('cancelled');

    $reservation = Reservation::findOrFail($id);

    if ($cancelled) {
        return ['status' => false, 'reservation' => $reservation->toArray()];
    }

    if ($token) {
        try {
            $ordersController = $this->client->getOrdersController();
            $response = $ordersController->ordersCapture(['id' => $token]);
            $result = $response->getResult();

            // SECURITY: Validate order belongs to this reservation
            $referenceId = $result->getPurchaseUnits()[0]->getReferenceId();
            if ($referenceId !== (string) $reservation->id) {
                Log::warning('PayPal order reference mismatch', [
                    'expected' => $reservation->id,
                    'got' => $referenceId,
                ]);
                return ['status' => false, 'reservation' => $reservation->toArray()];
            }

            if ($result->getStatus() === 'COMPLETED') {
                // ... existing capture logic
            }
        } catch (\Exception $e) {
            Log::error('PayPal capture failed: ' . $e->getMessage());
        }
    }

    return ['status' => false, 'reservation' => $reservation->toArray()];
}
```

---

### 3. Missing Order-Reservation Binding ✅ FIXED

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:59-105, 155-205`
**Severity:** 🔴 Critical
**CVSS Estimate:** 7.5 (High)
**Status:** ✅ Fixed - pending_order_id is now stored and validated to prevent token substitution

**Description:**
There is no mechanism to ensure that the PayPal order token used in `handleRedirectBack()` was actually created for the specified reservation. An attacker could:
1. Create a legitimate PayPal order for a small amount on reservation A
2. Use that order token with a different reservation ID (B)
3. Potentially complete payment for reservation B using order A's token

**Recommended Fix:**
Store the pending order ID when creating the payment intent:

```php
// In paymentIntent():
$reservation->update(['pending_order_id' => $result->getId()]);

// In handleRedirectBack():
if ($reservation->pending_order_id !== $token) {
    Log::warning('PayPal order token mismatch');
    abort(403);
}
```

---

## Medium Security Issues

### 4. OAuth Access Token Not Cached

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:43-57`
**Severity:** 🟠 Medium

**Description:**
Every webhook verification requests a new OAuth access token from PayPal. Tokens are valid for ~9 hours but are never cached.

**Impact:**
- Performance degradation under load
- Increased latency for webhook processing
- May hit PayPal API rate limits
- Unnecessary API calls increase attack surface

**Recommended Fix:**
```php
protected function getAccessToken(): string
{
    $cacheKey = 'paypal_access_token_' . config('services.paypal.mode');

    return Cache::remember($cacheKey, now()->addHours(8), function () {
        $response = Http::timeout(30)
            ->withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.client_secret')
            )
            ->asForm()
            ->post($this->getPaypalApiBaseUrl() . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Failed to obtain PayPal access token');
        }

        return $response->json('access_token');
    });
}
```

---

### 5. Certificate URL Not Validated

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:285`
**Severity:** 🟠 Medium

**Description:**
The `PAYPAL-CERT-URL` header is passed directly without validation. While PayPal's API validates this, defense-in-depth suggests early validation.

**Recommended Fix:**
```php
protected function isValidPaypalCertUrl(?string $url): bool
{
    if (empty($url)) {
        return false;
    }

    $parsed = parse_url($url);
    $validHosts = [
        'api.paypal.com',
        'api.sandbox.paypal.com',
        'api-m.paypal.com',
        'api-m.sandbox.paypal.com',
    ];

    return isset($parsed['scheme'], $parsed['host'])
        && $parsed['scheme'] === 'https'
        && in_array($parsed['host'], $validHosts, true);
}

// In verifyWebhookSignature():
if (!$this->isValidPaypalCertUrl($headers['cert_url'])) {
    Log::warning('PayPal webhook: Invalid cert URL');
    return false;
}
```

---

### 6. No HTTP Request Timeouts

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:45-50, 301-310`
**Severity:** 🟠 Medium

**Description:**
HTTP requests to PayPal API don't specify timeouts. Slow or hanging responses can exhaust PHP workers.

**Recommended Fix:**
```php
// Add timeout() to all HTTP calls:
$response = Http::timeout(30)->retry(3, 100)->withBasicAuth(...)->post(...);
$response = Http::timeout(30)->retry(3, 100)->withToken($accessToken)->post(...);
```

---

## Low Severity Issues

### 7. `verifyWebhook()` Method Always Returns True

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:270-273`

The method always returns `true` which is misleading. Add documentation explaining that actual verification happens in `verifyPayment()`.

---

### 8. Entry Title Not Sanitized

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:71`

```php
->description($reservation->entry()->title)
```

Consider sanitizing: `Str::limit(strip_tags($reservation->entry()->title), 127)`

---

### 9. Sensitive Data May Be Logged

**Location:** `src/Http/Payment/PaypalPaymentGateway.php:313`

Response bodies may contain sensitive data. Log status codes instead of full bodies.

---

### 10. Missing Idempotency Headers

PayPal recommends `PayPal-Request-Id` headers to prevent duplicate order creation.

---

## Test Coverage Gaps

The following scenarios lack test coverage:

1. ✗ Successful webhook signature verification (end-to-end)
2. ✗ `paymentIntent()` order creation flow
3. ✗ `refund()` functionality
4. ✗ `handleRedirectBack()` capture flow
5. ✗ Authorization/IDOR attack scenarios
6. ✗ Duplicate webhook handling
7. ✗ Edge cases (expired orders, partial captures, etc.)

---

## Recommendations Summary

| Priority | Action |
|----------|--------|
| P0 | Move webhook signature verification before any database operations |
| P0 | Validate order `reference_id` matches reservation in `handleRedirectBack()` |
| P0 | Store and validate pending order ID binding |
| P1 | Cache OAuth access tokens |
| P1 | Add HTTP request timeouts |
| P1 | Validate PayPal certificate URL domain |
| P2 | Add retry logic for HTTP requests |
| P2 | Sanitize entry title in order description |
| P2 | Improve error logging (avoid sensitive data) |
| P3 | Add idempotency headers |
| P3 | Expand test coverage |

---

## Compliance Considerations

For PCI-DSS compliance, ensure:
- All API credentials stored securely (environment variables ✓)
- Logging does not capture full card/payment details (verify)
- Error messages don't leak sensitive information (needs review)
- Webhook endpoints are properly secured (signature verification ✓, but order matters)

---

*This review was conducted as a static code analysis. Runtime testing and penetration testing are recommended for production deployments.*
