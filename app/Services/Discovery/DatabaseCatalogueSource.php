<?php

namespace App\Services\Discovery;

use App\Contracts\CatalogueSource;
use App\Enums\ShippingMethodType;
use App\Services\Shipping\ShippingQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The catalogue as the commerce tables hold it (D-50): brands (products.brand_id), the structured attributes a
 * seller typed (product_attributes, with their label and numeric twin), the options of active variants, the region of
 * the store (stores.region) and the delivery window of the store's cheapest delivery method.
 *
 * It is the ONLY place discovery learns the shape of those tables.
 */
class DatabaseCatalogueSource implements CatalogueSource
{
    /** @var array<int, array{min: int, max: int}|null> store id => window, remembered for the request */
    private array $deliveryByStore = [];

    public function brands(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('products')->join('brands', 'brands.id', '=', 'products.brand_id')
            ->whereIn('products.id', $productIds)->where('brands.is_active', true)
            ->pluck('brands.name', 'products.id')->map(fn ($name) => (string) $name)->all();
    }

    public function attributes(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('product_attributes')->whereIn('product_id', $productIds)->orderBy('position')->orderBy('id')
            ->get(['product_id', 'key', 'label', 'value', 'value_number', 'unit']) as $row) {
            if ($row->value === null || $row->value === '') {
                continue;
            }

            $out[(int) $row->product_id][] = [
                'key' => Str::slug((string) $row->key, '_'),
                'label' => (string) $row->label,
                'value' => (string) $row->value,
                'number' => $row->value_number !== null ? (float) $row->value_number : null,
                'unit' => $row->unit !== null && $row->unit !== '' ? (string) $row->unit : null,
            ];
        }

        return $out;
    }

    public function variantOptions(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('product_variants')->whereIn('product_id', $productIds)->where('is_active', true)->whereNotNull('options')->orderBy('position')->orderBy('id')
            ->get(['product_id', 'options']) as $row) {
            $options = json_decode((string) $row->options, true);

            if (! is_array($options)) {
                continue;
            }

            foreach ($options as $name => $value) {
                $value = trim((string) $value);

                if ($value !== '' && ! in_array($value, $out[(int) $row->product_id][(string) $name] ?? [], true)) {
                    $out[(int) $row->product_id][(string) $name][] = $value;
                }
            }
        }

        return $out;
    }

    public function storeRegions(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        return DB::table('stores')->whereIn('id', $storeIds)->whereNotNull('region')->where('region', '!=', '')
            ->pluck('region', 'id')->map(fn ($v) => (string) $v)->all();
    }

    public function deliveryEstimate(int $productId): ?array
    {
        $storeId = DB::table('products')->where('id', $productId)->value('store_id');

        if ($storeId === null) {
            return null;
        }

        $storeId = (int) $storeId;

        if (! array_key_exists($storeId, $this->deliveryByStore)) {
            $this->deliveryByStore[$storeId] = $this->windowFor($storeId);
        }

        return $this->deliveryByStore[$storeId];
    }

    /** The shopper-facing quote for "no destination known yet": only rule-free methods, cheapest first, delivery only. */
    private function windowFor(int $storeId): ?array
    {
        $quote = app(ShippingQuote::class)->forStores([$storeId => 0], null)[$storeId] ?? null;

        foreach ($quote?->options ?? [] as $option) {
            if ($option->methodId !== null && $option->type !== ShippingMethodType::Collection->value) {
                return ['min' => $option->handlingDays + $option->windowMinDays, 'max' => $option->handlingDays + max($option->windowMinDays, $option->windowMaxDays)];
            }
        }

        return null;
    }
}
