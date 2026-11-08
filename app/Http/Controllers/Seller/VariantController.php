<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SellerProductController;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Catalogue\Inventory;
use App\Services\Catalogue\ProductVariants;
use App\Services\Catalogue\VariantSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Options ("Size: S, M, L") and the variants they make, with a price, stock and picture each. Every action is for one of the
 * seller's own products (ProductPolicy::update); a variant is only ever looked up through the product it is posted under.
 */
class VariantController extends Controller
{
    /**
     * Defines the options. An empty list takes them all away and puts the product back to a single price and stock (the options'
     * variants are switched off, or deleted if nobody ever ordered them).
     */
    public function options(Request $request, Product $product, ProductVariants $variants, VariantSync $sync): RedirectResponse
    {
        Gate::authorize('update', $product);

        $data = $request->validate([
            'options' => 'nullable|array|max:'.ProductVariants::MAX_OPTIONS,
            'options.*.name' => 'nullable|string|max:40',
            'options.*.values' => 'nullable|string|max:1000',
        ]);

        $definition = [];
        foreach ((array) ($data['options'] ?? []) as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $values = collect(preg_split('/[,\n]+/', (string) ($row['values'] ?? '')))->map(fn ($v) => trim($v))->filter()->all();

            if ($name === '' && $values === []) {
                continue;
            }
            if ($name === '' || $values === []) {
                return back()->withErrors(['options' => 'Give every option a name and at least one value, separated by commas (for example Size: S, M, L).'])->withInput();
            }
            if (isset($definition[$name])) {
                return back()->withErrors(['options' => "\"{$name}\" is listed twice."])->withInput();
            }
            $definition[$name] = $values;
        }

        try {
            DB::transaction(function () use ($product, $definition, $variants, $sync) {
                $variants->defineOptions($product, $definition);

                foreach (ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->get() as $variant) {
                    $stale = $definition === []
                        ? $variant->options !== null
                        : ($variant->options !== null && ! $this->fits($variant->options, $definition));
                    if ($stale) {
                        $variants->remove($variant);
                    }
                }

                if ($definition === []) {
                    // Back to a simple product: its implicit variant comes back (or is switched on again).
                    $default = ProductVariant::query()->where('product_id', $product->id)->where('option_key', '')->first();
                    $default ? $default->update(['is_active' => true, 'is_default' => true]) : $sync->ensureDefaultVariant($product->refresh());
                    $sync->refreshProduct($product->id);
                }
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('seller.products.edit', $product)->withFragment('variants')->with('status', $definition === []
            ? 'Options removed. This product has one price and one stock number again.'
            : 'Options saved. Set a price and stock for each combination below; a combination you leave blank is not sold.');
    }

    /**
     * The price, stock, SKU, picture and on/off of each combination. A row left without a price is not created. Stock is saved as
     * the seller's change against the number they were looking at (`stock_was`), so units sold meanwhile are never put back.
     */
    public function variants(Request $request, Product $product, ProductVariants $variants, Inventory $inventory): RedirectResponse
    {
        Gate::authorize('update', $product);

        $request->validate(['rows' => 'required|array|max:'.ProductVariants::MAX_VARIANTS]);
        $imageIds = ProductImage::query()->where('product_id', $product->id)->pluck('id')->all();
        $errors = [];
        $saved = 0;

        foreach ((array) $request->input('rows') as $i => $row) {
            $choices = (array) ($row['options'] ?? []);
            $existing = ProductVariant::query()->where('product_id', $product->id)->where('option_key', ProductVariant::keyFor($choices))->first();
            $price = $row['price'] ?? null;

            if (! $existing && ($price === null || $price === '')) {
                continue; // a combination the seller does not sell
            }

            $rules = [
                'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.SellerProductController::MAX_PRICE],
                'stock' => ['nullable', 'integer', 'min:0', 'max:'.SellerProductController::MAX_STOCK],
                'stock_was' => ['nullable', 'integer', 'min:0', 'max:'.SellerProductController::MAX_STOCK],
                'sku' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($existing?->id), 'unique:products,sku'],
                'image_id' => ['nullable', Rule::in($imageIds)],
            ];
            $validator = validator($row, $rules, ['image_id.in' => 'Pick one of this listing\'s own photos.', 'sku.unique' => 'That SKU is already used by another product or option.']);

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["rows.{$i}.{$field}"] = $messages[0];
                }
                continue;
            }

            try {
                $variant = $variants->saveVariant($product, $choices, [
                    'price' => $row['price'],
                    'sku' => $row['sku'] ?? null,
                    'is_active' => ! empty($row['is_active']),
                    'image_id' => filled($row['image_id'] ?? null) ? (int) $row['image_id'] : null,
                ]);

                if (isset($row['stock']) && $row['stock'] !== '') {
                    $inventory->setStock($variant, (int) $row['stock'], isset($row['stock_was']) && $row['stock_was'] !== '' ? (int) $row['stock_was'] : ($existing ? null : 0), $request->user()->id);
                }
                $saved++;
            } catch (ValidationException $e) {
                $errors["rows.{$i}.price"] = collect($e->errors())->flatten()->first();
            }
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        return redirect()->route('seller.products.edit', $product)->withFragment('variants')->with('status', $saved === 0 ? 'Nothing to save: give at least one combination a price.' : 'Saved '.$saved.' '.($saved === 1 ? 'combination' : 'combinations').'.');
    }

    /** Whole-listing stock edit for products with options: one number per combination, each as a change against what was seen. */
    public function stock(Request $request, Product $product, Inventory $inventory): RedirectResponse
    {
        Gate::authorize('update', $product);

        $data = $request->validate([
            'stock' => 'required|array|max:'.ProductVariants::MAX_VARIANTS,
            'stock.*.value' => 'required|integer|min:0|max:'.SellerProductController::MAX_STOCK,
            'stock.*.was' => 'nullable|integer|min:0|max:'.SellerProductController::MAX_STOCK,
        ]);

        $own = ProductVariant::query()->where('product_id', $product->id)->whereIn('id', array_keys($data['stock']))->get()->keyBy('id');
        $adjusted = 0;

        foreach ($data['stock'] as $id => $row) {
            if (! $own->has((int) $id)) {
                continue; // a variant of somebody else's product is not ours to touch
            }
            $was = isset($row['was']) ? (int) $row['was'] : null;
            $stored = $inventory->setStock($own[(int) $id], (int) $row['value'], $was, $request->user()->id);
            $adjusted += $stored !== (int) $row['value'] ? 1 : 0;
        }

        return redirect()->route('seller.products.edit', $product)->withFragment('variants')->with('status', $adjusted === 0 ? 'Stock saved.' : 'Stock saved. '.$adjusted.' '.($adjusted === 1 ? 'number differs' : 'numbers differ').' from the form, because units were sold while you were editing.');
    }

    /** @param array<string, string> $options  @param array<string, array<int, string>> $definition */
    private function fits(array $options, array $definition): bool
    {
        if (count($options) !== count($definition)) {
            return false;
        }
        foreach ($options as $name => $value) {
            if (! isset($definition[$name]) || ! in_array($value, $definition[$name], true)) {
                return false;
            }
        }

        return true;
    }
}
