<?php

namespace App\Services\Search;

/**
 * The answer to a search: one page of items, the total, the facets (all counts real), what was understood and
 * applied, and the honest notes about how the result was reached (a corrected word, a relaxed match).
 */
final class SearchResult
{
    /**
     * @param  list<SearchHit>  $items
     * @param  array<string, mixed>  $facets
     * @param  list<array{key: string, label: string, value: string, inferred: bool}>  $appliedFilters
     * @param  list<array{kind: string, text: string}>  $understood
     * @param  list<string>  $notes
     * @param  list<array{remove: string, label: string, count: int}>  $relaxations  when nothing matched: what dropping one filter would give
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $facets,
        public readonly ?string $didYouMean,
        public readonly ?string $correctedFrom,
        public readonly bool $relaxed,
        public readonly array $appliedFilters,
        public readonly array $understood,
        public readonly string $engine,
        public readonly string $sort,
        public readonly ?string $sortNote,
        public readonly string $normalisedQuery,
        public readonly array $notes = [],
        public readonly array $relaxations = [],
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return array_map(fn (SearchHit $hit) => $hit->product->id, $this->items);
    }
}
