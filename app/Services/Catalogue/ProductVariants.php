<?php

namespace App\Services\Catalogue;

use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seller-side management of options and variants.
 *
 * defineOptions() sets the axes ("Size": S, M, L; "Colour": Red, Blue). saveVariant() creates or updates ONE
 * variant by its option choices; the first time options are defined, the implicit default variant is turned into
 * the first real variant or retired. A variant that has been ordered is deactivated, never deleted, so history
 * keeps resolving.
 */
class ProductVariants
{
    public const MAX_OPTIONS = 3;

    public const MAX_VARIANTS = 100;

    /**
     * @param  array<string, array<int, string>>  $options  ['Size' => ['S', 'M'], ...] in the order to show
     */
    public function defineOptions(Product $product, array $options): void
    {
        if (count($options) > self::MAX_OPTIONS) {
            throw ValidationException::withMessages(['options' => 'A product can have at most '.self::MAX_OPTIONS.' options.']);
        }

        DB::transaction(function () use ($product, $options) {
            Product::query()->lockForUpdate()->findOrFail($product->id);

            ProductOption::query()->where('product_id', $product->id)->delete();

            $position = 0;

            foreach ($options as $name => $values) {
                $values = collect($values)->map(fn ($v) => trim((string) $v))->filter()->unique(fn ($v) => mb_strtolower($v))->values()->all();
                $name = trim((string) $name);

                if ($name === '' || $values === [] || count($values) > 50) {
                    throw ValidationException::withMessages(['options' => "Option \"{$name}\" needs between 1 and 50 values."]);
                }

                ProductOption::create(['product_id' => $product->id, 'name' => $name, 'position' => $position++, 'values' => $values]);
            }
        });
    }

    /**
     * @param  array<string, string>  $choices  option name => chosen value (empty for the implicit variant)
     * @param  array{sku?: ?string, price: float|int|string, stock?: int, is_active?: bool, image_id?: ?int}  $data
     *
     * @throws ValidationException
     */
    public function saveVariant(Product $product, array $choices, array $data): ProductVariant
    {
        $this->assertChoicesAreValid($product, $choices);

        $price = $data['price'] ?? null;

        if (! is_numeric($price) || Money::toCents($price) < 0 || (float) $price > 10000000) {
            throw ValidationException::withMessages(['price' => 'Enter a price between 0 and 10,000,000.']);
        }

        return DB::transaction(function () use ($product, $choices, $data) {
            Product::query()->lockForUpdate()->findOrFail($product->id);

            $key = ProductVariant::keyFor($choices);
            $variant = ProductVariant::query()->where('product_id', $product->id)->where('option_key', $key)->first();

            if ($variant === null && ProductVariant::query()->where('product_id', $product->id)->count() >= self::MAX_VARIANTS) {
                throw ValidationException::withMessages(['options' => 'A product can have at most '.self::MAX_VARIANTS.' variants.']);
            }

            if ($variant === null && $choices !== []) {
                // The implicit variant stands for "no options chosen": once real options exist, it is retired.
                $this->retireImplicitVariant($product);
            }

            $variant ??= new ProductVariant(['product_id' => $product->id, 'options' => $choices === [] ? null : $choices]);
            // Only what the caller sent changes: saving a new price must not blank the SKU or switch the variant back on.
            $variant->price = $data['price'];

            if (array_key_exists('sku', $data)) {
                $variant->sku = $data['sku'] ?: null;
            }

            if (array_key_exists('image_id', $data)) {
                $variant->image_id = $data['image_id'];
            }

            if (array_key_exists('is_active', $data)) {
                $variant->is_active = (bool) $data['is_active'];
            } elseif (! $variant->exists) {
                $variant->is_active = true;
            }

            $isNew = ! $variant->exists;

            if ($isNew) {
                $variant->stock = 0;
                $variant->is_default = $choices === [];
                $variant->position = (int) ProductVariant::query()->where('product_id', $product->id)->max('position') + 1;
            }

            $variant->save();

            if (array_key_exists('stock', $data)) {
                app(Inventory::class)->setStock($variant, (int) $data['stock'], $isNew ? 0 : null, Auth::id(), $isNew ? 'initial' : 'seller_edit');
            }

            app(VariantSync::class)->refreshProduct($product->id);

            return $variant->refresh();
        });
    }

    public function remove(ProductVariant $variant): void
    {
        DB::transaction(function () use ($variant) {
            $ordered = OrderItem::query()->where('variant_id', $variant->id)->exists();

            if ($ordered || $variant->is_default) {
                $variant->update(['is_active' => false]);
            } else {
                $variant->delete();
            }

            app(VariantSync::class)->refreshProduct($variant->product_id);
        });
    }

    private function retireImplicitVariant(Product $product): void
    {
        $implicit = ProductVariant::query()->where('product_id', $product->id)->where('option_key', '')->first();

        if ($implicit === null) {
            return;
        }

        if (OrderItem::query()->where('variant_id', $implicit->id)->exists() || CartItem::withoutGlobalScopes()->where('variant_id', $implicit->id)->exists()) {
            $implicit->update(['is_active' => false, 'is_default' => false]);
        } else {
            $implicit->delete();
        }
    }

    /**
     * @param  array<string, string>  $choices
     */
    private function assertChoicesAreValid(Product $product, array $choices): void
    {
        $options = ProductOption::query()->where('product_id', $product->id)->get()->keyBy('name');

        if (count($choices) !== $options->count()) {
            throw ValidationException::withMessages(['options' => 'Choose a value for every option: '.$options->keys()->implode(', ').'.']);
        }

        foreach ($choices as $name => $value) {
            $option = $options->get($name);

            if ($option === null || ! in_array($value, $option->values, true)) {
                throw ValidationException::withMessages(['options' => "\"{$value}\" is not a value of \"{$name}\"."]);
            }
        }
    }
}
