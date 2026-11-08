<?php

namespace App\Services\Search;

/**
 * What a typed request means, in structure.
 *
 * "samsung 55 inch tv under R15,000" becomes: brand Samsung, category Televisions, attribute screen size 55,
 * price at most 15000, and no leftover words. Each detected piece is an INFERRED filter: the engine applies it,
 * shows it as a removable chip, and if it makes the search come back empty it tries again without it.
 *
 * A "slot" is one meaning in the request with its alternatives: [["laptop"], ["notebook"]] means laptop OR
 * notebook; a multi-word alternative such as "cell phone" means both words. Slots are ANDed together.
 */
final class ParsedQuery
{
    /**
     * @param  list<string>  $tokens  every meaningful word, folded
     * @param  list<list<string>>  $slots  words left over after detections
     * @param  list<list<string>>  $fullSlots  every word, including the ones that became filters
     * @param  array<string, mixed>  $inferred  category_id, category_label, brand, price_min, price_max, in_stock, attributes
     * @param  list<array{kind: string, text: string}>  $detected  what was recognised, for display
     * @param  list<array{from: string, to: string}>  $synonymsUsed
     */
    public function __construct(
        public readonly string $raw,
        public readonly string $normalised,
        public readonly array $tokens,
        public readonly array $slots,
        public readonly array $fullSlots,
        public readonly array $inferred,
        public readonly array $detected,
        public readonly array $synonymsUsed = [],
    ) {}

    public function hasText(): bool
    {
        return $this->slots !== [];
    }

    public function hasInferredFilters(): bool
    {
        return $this->inferred !== [] && array_diff(array_keys($this->inferred), ['category_label']) !== [];
    }

    /** Plain residual text, first alternative of each slot. */
    public function residualText(): string
    {
        return implode(' ', array_map(fn (array $alternatives) => $alternatives[0], $this->slots));
    }
}
