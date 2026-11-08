<?php

namespace App\Services\Discovery;

/**
 * A titled block of recommendations. `basis` says in one honest sentence what it is made from; `editorial` is true
 * when the link was set by people at the marketplace (complementary rules), not measured from shoppers.
 */
final class RecommendationSet
{
    /** @param  list<RecommendedItem>  $items */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $basis,
        public readonly array $items,
        public readonly bool $editorial = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
