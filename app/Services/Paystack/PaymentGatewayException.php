<?php

namespace App\Services\Paystack;

use RuntimeException;

/**
 * Anything that stops us talking to Paystack. The message is written to be
 * shown to a buyer or an operator: it never contains a key, a request or a
 * response body, however the failure happened.
 */
class PaymentGatewayException extends RuntimeException
{
    private function __construct(string $message, private readonly bool $temporary, private readonly bool $currencyMismatch = false)
    {
        parent::__construct($message);
    }

    /**
     * Worth retrying later (outage, timeout, Paystack 5xx, rate limit).
     */
    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    /**
     * The message is safe for the buyer: it names the order's currency and
     * the gateway's, never a key.
     */
    public static function currencyMismatch(string $orderCurrency, string $gatewayCurrency): self
    {
        return new self("This order was placed in {$orderCurrency}, but card payments now charge in {$gatewayCurrency}, so it cannot be paid by card. Nothing was charged. Please contact the store operator.", false, true);
    }

    /**
     * The order itself is in a currency the gateway no longer charges in.
     */
    public function isCurrencyMismatch(): bool
    {
        return $this->currencyMismatch;
    }

    public static function notConfigured(): self
    {
        return new self('Card payments are not configured on this platform.', false);
    }

    public static function misconfigured(string $problem): self
    {
        return new self($problem, false);
    }

    public static function unavailable(): self
    {
        return new self('We could not reach Paystack just now.', true);
    }

    public static function rejected(int $status): self
    {
        return new self("Paystack refused the request (HTTP {$status}).", $status === 429);
    }

    public static function serverError(int $status): self
    {
        return new self("Paystack had a problem on its side (HTTP {$status}).", true);
    }

    public static function malformed(): self
    {
        return new self('Paystack sent a reply we could not understand.', false);
    }
}
