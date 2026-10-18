<?php

namespace App\Services\Paystack;

use Illuminate\Support\Facades\Log;

/**
 * The one reader of config/paystack.php, and the TEST-MODE GUARD.
 *
 *  - not configured: no secret key set. Checkout behaves as it did before
 *    Paystack (orders pending and unpaid).
 *  - misconfigured: a key is set but must not be used: a `sk_live_` key
 *    without PAYSTACK_ALLOW_LIVE=true, a key that is not a Paystack secret
 *    key at all, or a non-https base URL. secretKey() refuses to hand such a
 *    key out, so nothing can ever send it anywhere.
 *  - test mode: the key starts `sk_test_`.
 *
 * Reads config on every call (cheap) so a changed .env or a test override
 * is honoured without rebuilding anything.
 */
class PaystackConfig
{
    public function isConfigured(): bool
    {
        return $this->rawKey() !== '';
    }

    public function isMisconfigured(): bool
    {
        return $this->isConfigured() && $this->problem() !== null;
    }

    public function isUsable(): bool
    {
        return $this->isConfigured() && $this->problem() === null;
    }

    public function isTestMode(): bool
    {
        return $this->isUsable() && str_starts_with($this->rawKey(), 'sk_test_');
    }

    /**
     * Why a configured key is refused, in words safe to show an operator;
     * null when it is fine.
     */
    public function problem(): ?string
    {
        $key = $this->rawKey();

        if ($key === '') {
            return null;
        }

        if (! preg_match('/^sk_(test|live)_[A-Za-z0-9_\-]+$/', $key)) {
            return 'PAYSTACK_SECRET_KEY is not a Paystack secret key (it must start with sk_test_ or sk_live_). It was refused and nothing was sent.';
        }

        if (str_starts_with($key, 'sk_live_') && ! (bool) config('paystack.allow_live')) {
            return 'A live Paystack key is set but PAYSTACK_ALLOW_LIVE is not true, so it was refused and nothing was sent. Use a test key, or enable live payments deliberately.';
        }

        if (! str_starts_with((string) config('paystack.base_url'), 'https://')) {
            return 'PAYSTACK_BASE_URL must be an https:// URL. It was refused and nothing was sent.';
        }

        return null;
    }

    /**
     * @throws PaymentGatewayException when there is no key or it is refused
     */
    public function secretKey(): string
    {
        if (! $this->isConfigured()) {
            throw PaymentGatewayException::notConfigured();
        }

        if (($problem = $this->problem()) !== null) {
            // The refusal is logged (never the key) so it is not silent.
            Log::critical('Paystack key refused', ['allow_live' => (bool) config('paystack.allow_live')]);

            throw PaymentGatewayException::misconfigured($problem);
        }

        return $this->rawKey();
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('paystack.base_url'), '/');
    }

    public function currency(): string
    {
        return strtoupper((string) (config('paystack.currency') ?: 'ZAR'));
    }

    public function unpaidWindowMinutes(): int
    {
        $minutes = (int) config('paystack.unpaid_window_minutes');

        return $minutes > 0 ? $minutes : 60;
    }

    public function attemptTtlMinutes(): int
    {
        $minutes = (int) config('paystack.attempt_ttl_minutes');

        return $minutes > 0 ? $minutes : 30;
    }

    private function rawKey(): string
    {
        return trim((string) config('paystack.secret_key'));
    }
}
