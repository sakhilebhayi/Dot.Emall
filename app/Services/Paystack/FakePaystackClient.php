<?php

namespace App\Services\Paystack;

use Illuminate\Support\Facades\Cache;

/**
 * TESTING ONLY. Stands in for Paystack's hosted page when EMALL_E2E_FAKE_PAYSTACK is on in a local or testing environment
 * (App\Providers\CheckoutServiceProvider decides; this class is never bound anywhere else). "Initialize" parks the attempt in the
 * cache and returns a local page (dev.fake-paystack) where a person presses Pay, Fail or Abandon; "verify" answers from what they
 * pressed. The payment code above it is the real code: nothing is marked paid except through PaymentService, exactly as in production.
 */
class FakePaystackClient extends PaystackClient
{
    public const CACHE_PREFIX = 'fake-paystack:';

    public function initializeTransaction(string $email, int $amountCents, string $currency, string $reference, string $callbackUrl, array $metadata = []): InitializedTransaction
    {
        Cache::put(self::CACHE_PREFIX.$reference, [
            'status' => 'pending', 'amount' => $amountCents, 'currency' => $currency, 'callback' => $callbackUrl, 'email' => $email,
        ], now()->addHours(3));

        return new InitializedTransaction(route('dev.fake-paystack', ['reference' => $reference]), 'fake-access-code', $reference);
    }

    public function verifyTransaction(string $reference): VerifiedTransaction
    {
        $state = Cache::get(self::CACHE_PREFIX.$reference);

        if (! is_array($state)) {
            throw PaymentGatewayException::unavailable();
        }

        return new VerifiedTransaction(
            status: $state['status'] === 'pending' ? 'abandoned' : $state['status'],
            amountCents: (int) $state['amount'],
            currency: (string) $state['currency'],
            reference: $reference,
            transactionId: (string) (900000000 + (crc32($reference) % 99999999)),
            paidAt: $state['status'] === 'success' ? now()->toIso8601String() : null,
            gatewayResponse: $state['status'] === 'success' ? 'Successful (fake gateway)' : 'Not completed (fake gateway)',
            channel: $state['status'] === 'success' ? 'card' : null,
        );
    }

    public function createRefund(string $transactionReference, int $amountCents, string $currency, ?string $customerNote = null, ?string $merchantNote = null): CreatedRefund
    {
        return new CreatedRefund('fake-refund-'.crc32($transactionReference.$amountCents), 'pending');
    }
}
