<?php

namespace App\Services\Shipping;

use Illuminate\Support\Carbon;

/**
 * One way a seller group can reach the buyer, priced for THIS cart and THIS region. The dates are an ESTIMATE
 * (seller handling time + the method's window, working days); estimateLabel says so in words.
 */
final class ShippingOption
{
    public function __construct(
        public readonly ?int $methodId,
        public readonly string $name,
        public readonly string $type,           // flat | free_over | collection | arranged
        public readonly int $priceCents,
        public readonly bool $freeApplied,
        public readonly int $handlingDays,
        public readonly int $windowMinDays,
        public readonly int $windowMaxDays,
        public readonly ?Carbon $estimatedFrom,
        public readonly ?Carbon $estimatedTo,
        public readonly string $estimateLabel,
        public readonly ?string $instructions = null,
        public readonly ?string $collectionAddress = null,
        public readonly ?int $freeOverCents = null,
    ) {}

    public function isCollection(): bool
    {
        return $this->type === 'collection';
    }

    public function isArranged(): bool
    {
        return $this->type === 'arranged';
    }

    /** How much more the buyer must spend with this seller for a free_over method to become free; null when n/a. */
    public function remainingForFree(int $itemsCents): ?int
    {
        return $this->freeOverCents !== null && ! $this->freeApplied ? max(0, $this->freeOverCents - $itemsCents) : null;
    }
}
