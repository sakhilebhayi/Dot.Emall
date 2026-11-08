<?php

namespace App\Contracts;

/**
 * The seam between discovery and the commerce catalogue that owns brands, structured attributes, variants, shipping
 * and store regions. Discovery never reads those tables itself: App\Services\Discovery\DatabaseCatalogueSource
 * implements this against the real tables (D-50), and tests bind a fake to describe a catalogue in one line.
 *
 * Every method is batch shaped: callers pass many product ids and get one keyed answer, never a query per product.
 * The price history is NOT part of this seam: there is one history (App\Services\Catalogue\PriceHistory, written by
 * the variants' model events) and everything that needs a 30-day figure asks it.
 */
interface CatalogueSource
{
    /**
     * @param  array<int, int>  $productIds
     * @return array<int, string> product id => brand name (products without a brand are absent)
     */
    public function brands(array $productIds): array;

    /**
     * Structured attributes (spec facets) per product.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, list<array{key: string, label: string, value: string, number: float|null, unit: string|null}>>
     */
    public function attributes(array $productIds): array;

    /**
     * The choices a product's active variants offer, per option (Size: S, M, L; Colour: Red, Blue). A shopper searching
     * "red" or "size 42" finds the product through these; they are indexed beside the attributes.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, list<string>>> product id => option name => values (products with no options are absent)
     */
    public function variantOptions(array $productIds): array;

    /**
     * The region (province) a store ships from; stores with none recorded are absent.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, string> store id => region
     */
    public function storeRegions(array $storeIds): array;

    /**
     * The seller's own delivery window in WORKING days (handling plus the method's window) for the cheapest delivery
     * method that applies without a destination, or null when the store has set none (never guessed).
     *
     * @return array{min: int, max: int}|null
     */
    public function deliveryEstimate(int $productId): ?array;
}
