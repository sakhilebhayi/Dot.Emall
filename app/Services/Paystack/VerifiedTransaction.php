<?php

namespace App\Services\Paystack;

/**
 * What GET /transaction/verify/:reference said, with the amount already in
 * integer subunits (cents). Only whitelisted fields are kept: no customer
 * or card data.
 */
final readonly class VerifiedTransaction
{
    public function __construct(
        public string $status,
        public int $amountCents,
        public string $currency,
        public string $reference,
        public string $transactionId,
        public ?string $paidAt,
        public ?string $gatewayResponse,
        public ?string $channel,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return array_filter([
            'status' => $this->status,
            'gateway_response' => $this->gatewayResponse,
            'channel' => $this->channel,
            'paid_at' => $this->paidAt,
        ], fn ($v) => $v !== null);
    }
}
