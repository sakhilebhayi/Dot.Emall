<?php

namespace App\Services\Search;

use App\Models\Product;

/**
 * One result with the reason it is here. `why` is built from the fields that really contained the words searched
 * for ("matched title and brand"), never from a score.
 */
final class SearchHit
{
    /**
     * @param  list<string>  $matched  fields that matched: title, brand, category, attributes, store, description
     * @param  list<string>  $notes  extra honest context: a synonym used, a corrected word
     */
    public function __construct(
        public readonly Product $product,
        public readonly float $score,
        public readonly array $matched,
        public readonly array $notes,
        public readonly ?string $brand,
        public readonly bool $inStock,
    ) {}

    public function why(): string
    {
        $parts = [];

        if ($this->matched !== []) {
            $fields = $this->matched;
            $last = array_pop($fields);
            $parts[] = 'Matched '.($fields === [] ? $last : implode(', ', $fields).' and '.$last);
        }

        foreach ($this->notes as $note) {
            $parts[] = $note;
        }

        return implode('. ', $parts);
    }
}
