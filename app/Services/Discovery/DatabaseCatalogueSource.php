<?php

namespace App\Services\Discovery;

use App\Contracts\CatalogueSource;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads brands, structured attributes, store regions, price history and delivery estimates from whichever of the
 * commerce data tables exist, and answers "nothing known" for the rest. It is the ONLY place discovery learns the
 * shape of another workstream's tables, so reconciling the two branches means editing this class and nothing else.
 *
 * Shapes it understands (each checked with Schema::hasTable / hasColumn before use):
 *   brands            products.brand_id -> brands(id, name)            or a plain products.brand string column
 *   attributes        product_attributes(product_id, key|attribute_key|name, value|value_text, [value_number], [unit])
 *                     with labels from attribute_definitions(key|slug, label|name) when present
 *   store region      stores.region or stores.province
 *   price history     product_price_history(product_id, price, recorded_at|created_at)
 *   delivery          nothing is guessed; the shipping workstream binds its own source when ready
 */
class DatabaseCatalogueSource implements CatalogueSource
{
    public function brands(array $productIds): array
    {
        if ($productIds === [] || ! Schema::hasTable('products')) {
            return [];
        }

        try {
            if (Schema::hasColumn('products', 'brand_id') && Schema::hasTable('brands')) {
                return DB::table('products')->join('brands', 'brands.id', '=', 'products.brand_id')
                    ->whereIn('products.id', $productIds)->pluck('brands.name', 'products.id')
                    ->map(fn ($name) => (string) $name)->all();
            }

            if (Schema::hasColumn('products', 'brand')) {
                return DB::table('products')->whereIn('id', $productIds)->whereNotNull('brand')->where('brand', '!=', '')
                    ->pluck('brand', 'id')->map(fn ($name) => (string) $name)->all();
            }
        } catch (Throwable) {
        }

        return [];
    }

    public function attributes(array $productIds): array
    {
        if ($productIds === [] || ! Schema::hasTable('product_attributes') || ! Schema::hasColumn('product_attributes', 'product_id')) {
            return [];
        }

        try {
            $keyColumn = $this->firstColumn('product_attributes', ['attribute_key', 'key', 'name', 'slug']);
            $valueColumn = $this->firstColumn('product_attributes', ['value_text', 'value']);

            if ($keyColumn === null || $valueColumn === null) {
                return [];
            }

            $numberColumn = $this->firstColumn('product_attributes', ['value_number', 'value_num', 'number']);
            $unitColumn = $this->firstColumn('product_attributes', ['unit']);

            $labels = [];

            if (Schema::hasTable('attribute_definitions')) {
                $defKey = $this->firstColumn('attribute_definitions', ['key', 'slug', 'attribute_key']);
                $defLabel = $this->firstColumn('attribute_definitions', ['label', 'name']);

                if ($defKey !== null && $defLabel !== null) {
                    $labels = DB::table('attribute_definitions')->pluck($defLabel, $defKey)->all();
                }
            }

            $columns = ['product_id', $keyColumn.' as k', $valueColumn.' as v'];

            if ($numberColumn !== null) {
                $columns[] = $numberColumn.' as n';
            }

            if ($unitColumn !== null) {
                $columns[] = $unitColumn.' as u';
            }

            $out = [];

            foreach (DB::table('product_attributes')->whereIn('product_id', $productIds)->get($columns) as $row) {
                if ($row->v === null || $row->v === '') {
                    continue;
                }

                $key = Str::slug((string) $row->k, '_');
                $number = isset($row->n) && is_numeric($row->n) ? (float) $row->n : (is_numeric($row->v) ? (float) $row->v : null);

                $out[(int) $row->product_id][] = [
                    'key' => $key,
                    'label' => (string) ($labels[$row->k] ?? ucfirst(str_replace('_', ' ', $key))),
                    'value' => (string) $row->v,
                    'number' => $number,
                    'unit' => isset($row->u) && $row->u !== null && $row->u !== '' ? (string) $row->u : null,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    public function storeRegions(array $storeIds): array
    {
        if ($storeIds === [] || ! Schema::hasTable('stores')) {
            return [];
        }

        $column = $this->firstColumn('stores', ['region', 'province']);

        if ($column === null) {
            return [];
        }

        try {
            return DB::table('stores')->whereIn('id', $storeIds)->whereNotNull($column)->where($column, '!=', '')
                ->pluck($column, 'id')->map(fn ($v) => (string) $v)->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function priceHistory(int $productId): array
    {
        if (! Schema::hasTable('product_price_history') || ! Schema::hasColumn('product_price_history', 'price')) {
            return [];
        }

        $time = $this->firstColumn('product_price_history', ['recorded_at', 'created_at']);

        if ($time === null) {
            return [];
        }

        try {
            return DB::table('product_price_history')->where('product_id', $productId)->orderBy($time)->orderBy('id')
                ->get(['price', $time.' as at'])
                ->map(fn ($r) => ['price' => (float) $r->price, 'at' => Carbon::parse($r->at)])->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function deliveryEstimate(int $productId): ?array
    {
        return null;
    }

    /** @param  list<string>  $candidates */
    private function firstColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}
