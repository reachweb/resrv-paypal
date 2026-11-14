<?php

namespace Reach\ResrvPaymentPaypal\Http\Payment;

use PaypalServerSdkLib\Http\ApiResponse;

/**
 * A PayPal HTTP error. paypal-server-sdk 2.x does not throw for these: it returns an ApiResponse
 * whose isError() is true and whose result is the decoded error body (top level an array, nested
 * objects stdClass). PaypalApiTrait::paypalResult() turns that into this exception.
 */
class PaypalApiException extends \RuntimeException
{
    /**
     * @param  string[]  $issues  The `details[].issue` codes, e.g. INSTRUMENT_DECLINED.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $issues = [],
        public readonly ?string $debugId = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function fromResponse(ApiResponse $response): self
    {
        $body = $response->getResult();
        $status = (int) $response->getStatusCode();

        $issues = collect(data_get($body, 'details', []))
            ->map(fn ($detail) => data_get($detail, 'issue'))
            ->filter(fn ($issue) => is_string($issue))
            ->values()
            ->all();

        // OAuth errors use error/error_description instead of name/details.
        $name = data_get($body, 'name') ?? data_get($body, 'error') ?? 'HTTP_ERROR';

        return new self(
            "PayPal API error {$status} {$name}".($issues ? ' ['.implode(', ', $issues).']' : ''),
            $status,
            $issues,
            data_get($body, 'debug_id'),
        );
    }

    public function hasIssue(string $issue): bool
    {
        return in_array($issue, $this->issues, true);
    }
}
