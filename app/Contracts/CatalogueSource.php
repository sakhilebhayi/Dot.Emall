<?php

namespace App\Contracts;

/**
 * The seam between discovery (this workstream) and the commerce data workstream that owns brands, structured
 * attributes, price history, shipping and store regions.
 *
 * Discovery never reads those tables directly. App\Services\Discovery\DatabaseCatalogueSource implements this
 * against whatever exists (each lookup checks the schema first and answers "nothing known" when a table or column is
 * absent), so both branches merge cleanly and the merge only has to reconcile that one class. Tests bind a fake.
 *
 * Every method is batch shaped: callers pass many product ids and get one keyed answer, never a query per product.
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
     * The region a store ships from or serves (a province or area name); stores with none recorded are absent.
     *
     * @param  array<int, int>  $storeIds
     * @return array<int, string> store id => region
     */
    public function storeRegions(array $storeIds): array;

    /**
     * Recorded price changes for a product, oldest first. Empty when no history table exists.
     *
     * @return list<array{price: float, at: \DateTimeInterface}>
     */
    public function priceHistory(int $productId): array;

    /**
     * Estimated delivery window in days for a product, or null when nothing is recorded (never guessed).
     *
     * @return array{min: int, max: int}|null
     */
    public function deliveryEstimate(int $productId): ?array;
}
