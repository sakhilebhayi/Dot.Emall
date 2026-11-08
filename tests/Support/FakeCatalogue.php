<?php

namespace Tests\Support;

use App\Contracts\CatalogueSource;

/**
 * A stand-in for the catalogue source: tests say which product has which brand, attributes, variant options, region
 * and delivery window in one line, and bind this in the container. (Tests of the real tables use DatabaseCatalogueSource.) Nothing else about the catalogue is faked.
 */
class FakeCatalogue implements CatalogueSource
{
    /** @var array<int, string> */
    public array $brands = [];

    /** @var array<int, list<array{key: string, label: string, value: string, number: float|null, unit: string|null}>> */
    public array $attributes = [];

    /** @var array<int, string> */
    public array $storeRegions = [];

    /** @var array<int, array<string, list<string>>> */
    public array $options = [];

    /** @var array<int, array{min: int, max: int}> */
    public array $delivery = [];

    public function brands(array $productIds): array
    {
        return array_intersect_key($this->brands, array_flip($productIds));
    }

    public function attributes(array $productIds): array
    {
        return array_intersect_key($this->attributes, array_flip($productIds));
    }

    public function storeRegions(array $storeIds): array
    {
        return array_intersect_key($this->storeRegions, array_flip($storeIds));
    }

    public function variantOptions(array $productIds): array
    {
        return array_intersect_key($this->options, array_flip($productIds));
    }

    public function deliveryEstimate(int $productId): ?array
    {
        return $this->delivery[$productId] ?? null;
    }

    public function attribute(int $productId, string $key, string $label, string $value, ?float $number = null, ?string $unit = null): void
    {
        $this->attributes[$productId][] = compact('key', 'label', 'value', 'number', 'unit');
    }
}
