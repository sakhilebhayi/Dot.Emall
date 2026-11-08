<?php

namespace App\Services\Search;

/**
 * Searching the catalogue. Two implementations answer in the same shape: PostgresSearch (full-text, ranked) and
 * PortableSearch (tokenised LIKE with a scored order, for SQLite and any host without usable full-text). Callers
 * never choose: the container binds whichever the database supports (see SearchCapabilities).
 *
 * Ranking is text relevance, exact and prefix title matches, availability, and ratings only when a product has
 * enough real reviews. Nothing paid ever changes the order.
 */
interface SearchEngine
{
    public function name(): string;

    public function search(SearchRequest $request): SearchResult;

    /**
     * Listings whose title starts a word with the typed text, for autocomplete.
     *
     * @return list<array{id: int, title: string, price: string, image_id: int|null, store: string, in_stock: bool}>
     */
    public function suggestProducts(string $typed, int $limit = 6): array;
}
