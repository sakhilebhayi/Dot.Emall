<?php

namespace App\Services\Shipping;

/** The shipping choices for one seller group of a cart. */
final class GroupQuote
{
    /**
     * @param  array<int, ShippingOption>  $options  cheapest first; empty = the store cannot deliver to this region
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeName,
        public readonly int $itemsCents,
        public readonly array $options,
    ) {}

    public function cheapest(): ?ShippingOption
    {
        return $this->options[0] ?? null;
    }

    public function option(?int $methodId): ?ShippingOption
    {
        foreach ($this->options as $option) {
            if ($option->methodId === $methodId) {
                return $option;
            }
        }

        return null;
    }

    public function canDeliver(): bool
    {
        return $this->options !== [];
    }
}
