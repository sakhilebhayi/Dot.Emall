<?php

namespace App\Services\Catalogue;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Keeps products.price / stock / sku and the variants consistent.
 *
 *  - A product with no options has exactly one variant (the implicit default). Writing price, stock or sku on the
 *    PRODUCT (every existing form, factory and test does) is pushed to that variant, and a stock change leaves a
 *    StockMovement. Writing it on the variant is pulled back to the product.
 *  - A product with several variants shows its cheapest active variant's price and its active variants' total
 *    stock on the product row, so listings, sorting and the "in stock" filter keep working without a join.
 *
 * Work done here never re-triggers itself: syncing() is true while it runs.
 */
class VariantSync
{
    private static int $depth = 0;

    public static function syncing(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function quietly(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public function ensureDefaultVariant(Product $product): ProductVariant
    {
        return self::quietly(function () use ($product) {
            $existing = ProductVariant::query()->where('product_id', $product->id)->orderByDesc('is_default')->orderBy('id')->first();

            if ($existing !== null) {
                return $existing;
            }

            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'price' => $product->price,
                'stock' => (int) $product->stock,
                'is_active' => true,
                'is_default' => true,
            ]);

            if ($variant->stock > 0) {
                app(Inventory::class)->recordMovement($variant, 0, (int) $variant->stock, 'initial', Auth::id(), null, null, 'Opening stock');
            }

            return $variant;
        });
    }

    /**
     * Product row changed (price/stock/sku): carry it to the variants. A one-variant product mirrors it; a product
     * with options keeps its aggregates (the form's number is not a variant's number).
     */
    public function pushProductToVariants(Product $product): void
    {
        $variants = ProductVariant::query()->where('product_id', $product->id)->get();

        if ($variants->count() !== 1 || ! $variants->first()->is_default) {
            $this->refreshProduct($product->id);

            return;
        }

        $variant = $variants->first();

        self::quietly(function () use ($product, $variant) {
            $previousStock = (int) $variant->stock;

            $variant->forceFill([
                'price' => $product->price,
                'stock' => (int) $product->stock,
                'sku' => $product->sku,
            ]);
            $variant->save();

            if ($previousStock !== (int) $product->stock) {
                $variant->increment('stock_version');
                app(Inventory::class)->recordMovement($variant, (int) $product->stock - $previousStock, (int) $product->stock, 'adjustment', Auth::id(), null, null, 'Changed on the product');
            }
        });
    }

    /**
     * Recomputes the product's price (cheapest active variant) and stock (sum of active variants) from its variants.
     */
    public function refreshProduct(int $productId): void
    {
        $active = ProductVariant::query()->where('product_id', $productId)->where('is_active', true);

        $price = (clone $active)->min('price');
        $stock = (int) (clone $active)->sum('stock');

        $values = ['stock' => max(0, $stock)];

        if ($price !== null) {
            $values['price'] = $price;
        }

        $default = ProductVariant::query()->where('product_id', $productId)->orderByDesc('is_default')->orderBy('id')->first();

        if ($default !== null && ProductVariant::query()->where('product_id', $productId)->count() === 1) {
            $values['sku'] = $default->sku;
        }

        self::quietly(fn () => DB::table('products')->where('id', $productId)->update($values));
    }
}
