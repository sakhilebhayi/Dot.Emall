<?php

namespace App\Services\Discovery;

/**
 * One block of the homepage, with the real data behind it. `basis` is the sentence a shopper can read to learn why
 * the block is there; `evidence` holds the numbers it was built from. A section with nothing to show is never created.
 */
final class HomeSection
{
    /**
     * @param  string  $kind  products | categories | stores | collections | searches
     * @param  list<mixed>  $items  RecommendedItem for products; arrays for the rest
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $basis,
        public readonly string $kind,
        public readonly array $items,
        public readonly array $evidence = [],
        public readonly bool $personal = false,
    ) {}
}
