<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StoreCategory;
use App\Services\Catalogue\Inventory;
use App\Services\ProductImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    /**
     * A price a database column can hold with room to spare: the money columns are decimal(12,2), a line is price x up
     * to 99, so anything above this made checkout (and, on PostgreSQL, the listing itself) fail with a 500. Two
     * decimals at most: a third one used to be rounded away silently (0.001 became a free listing).
     */
    public const MAX_PRICE = 10000000;

    /** Far above any real shelf, far below the 32-bit integer the stock column is on PostgreSQL. */
    public const MAX_STOCK = 10000000;

    public function index(): View
    {
        $store = auth()->user()->store;

        $products = $store
            ? Product::where('store_id', $store->id)->latest()->get()
            : collect();

        return view('products.seller-index', compact('store', 'products'));
    }

    public function create(): View|RedirectResponse
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        $categories = StoreCategory::orderBy('name')->get();

        return view('products.create', ['store' => $store, 'categories' => $categories]);
    }

    public function store(Request $request, ProductImages $images): RedirectResponse
    {
        $store = auth()->user()->store;

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        Gate::authorize('create', [Product::class, $store]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|decimal:0,2|min:0|max:'.self::MAX_PRICE,
            'compare_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_PRICE,
            'stock' => 'required|integer|min:0|max:'.self::MAX_STOCK,
            'sku' => 'nullable|string|max:255|unique:products,sku',
            'category_id' => 'nullable|exists:store_categories,id',
            'brand' => 'nullable|string|max:100',
            'low_stock_threshold' => 'nullable|integer|min:0|max:'.self::MAX_STOCK,
            ...ProductImages::rules(),
        ]);

        $product = DB::transaction(function () use ($store, $validated, $request, $images) {
            $product = Product::create([
                'store_id' => $store->id,
                'category_id' => $validated['category_id'] ?? null,
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']).'-'.Str::random(6),
                'description' => $validated['description'] ?? null,
                'price' => $validated['price'],
                'compare_price' => $validated['compare_price'] ?? null,
                'stock' => $validated['stock'],
                'sku' => $validated['sku'] ?? null,
                'brand_id' => filled($validated['brand'] ?? null) ? Brand::named($validated['brand'])->id : null,
                'low_stock_threshold' => $validated['low_stock_threshold'] ?? 5,
                'status' => 'pending_approval',
            ]);

            // Decodes every photo before writing any: a bad one aborts the
            // whole listing rather than leaving it half-created.
            $images->add($product, $request->file('images', []));

            return $product;
        });

        return redirect()->route('products.show', $product)
            ->with('status', 'Listing submitted. It will appear in the marketplace once approved.');
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        $categories = StoreCategory::orderBy('name')->get();
        $product->load('images');

        // The form is pre-filled with today's stock. Remember that number, so update() can tell "the seller changed the
        // stock" from "the seller left it alone while buyers bought some of it".
        session([self::stockSeenKey($product) => (int) $product->stock]);

        return view('products.edit', compact('product', 'categories'));
    }

    /**
     * Editing does not reset an approved listing back to pending_approval
     * -- re-review on every price/stock tweak would make routine restocking
     * unusable, and moderation here only ever gated the original listing
     * (ProductModerationController), not every subsequent edit.
     */
    public function update(Product $product, Request $request, Inventory $inventory): RedirectResponse
    {
        Gate::authorize('update', $product);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|decimal:0,2|min:0|max:'.self::MAX_PRICE,
            'compare_price' => 'nullable|numeric|decimal:0,2|min:0|max:'.self::MAX_PRICE,
            'stock' => 'required|integer|min:0|max:'.self::MAX_STOCK,
            // The stock number the edit form was drawn with (a hidden field). Sturdier than the session: it survives an
            // expired session and two tabs.
            'stock_was' => 'nullable|integer|min:0|max:'.self::MAX_STOCK,
            'sku' => ['nullable', 'string', 'max:255', 'unique:products,sku,'.$product->id],
            'category_id' => 'nullable|exists:store_categories,id',
            'brand' => 'nullable|string|max:100',
            'low_stock_threshold' => 'nullable|integer|min:0|max:'.self::MAX_STOCK,
        ]);

        $adjusted = DB::transaction(function () use ($product, $validated, $request, $inventory) {
            // The row lock serialises this with checkout, which decrements stock under the same lock (products first,
            // then variants, always in that order).
            $current = Product::query()->lockForUpdate()->findOrFail($product->id);

            $simple = ProductVariant::query()->where('product_id', $current->id)->count() === 1;
            $variant = $simple ? ProductVariant::query()->where('product_id', $current->id)->lockForUpdate()->first() : null;

            $seen = isset($validated['stock_was']) ? (int) $validated['stock_was'] : $this->stockSeenFromSession($current);

            $values = [
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'compare_price' => $validated['compare_price'] ?? null,
                'sku' => $validated['sku'] ?? null,
                'category_id' => $validated['category_id'] ?? null,
            ];

            if ($request->has('brand')) {
                $values['brand_id'] = filled($validated['brand'] ?? null) ? Brand::named($validated['brand'])->id : null;
            }

            if (isset($validated['low_stock_threshold'])) {
                $values['low_stock_threshold'] = (int) $validated['low_stock_threshold'];
            }

            // Price and stock belong to the variant. A product with options has its own prices and stock per variant (set
            // there); the product row then only shows the cheapest price and the total stock.
            if ($variant !== null) {
                $values['price'] = $validated['price'];
            }

            $current->update($values);

            if ($variant === null) {
                return null;
            }

            $stock = $inventory->setStock($variant, (int) $validated['stock'], $seen, $request->user()->id);

            return $stock !== (int) $validated['stock'] ? $stock : null;
        });

        return redirect()->route('seller.products.index')->with('status', $adjusted === null
            ? 'Listing updated.'
            : "Listing updated. Stock is {$adjusted}, not the number on the form, because units were sold while you were editing.");
    }

    /**
     * Without the hidden `stock_was` field the form's number is remembered in the session (see edit()).
     */
    private function stockSeenFromSession(Product $current): ?int
    {
        $seen = session()->pull(self::stockSeenKey($current));

        return is_int($seen) ? $seen : null;
    }

    private static function stockSeenKey(Product $product): string
    {
        return 'stock_seen.'.$product->id;
    }

    public function toggleActive(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $product->update(['is_active' => ! $product->is_active]);

        return redirect()->route('seller.products.index')
            ->with('status', $product->is_active ? 'Listing reactivated.' : 'Listing deactivated.');
    }

    /**
     * Archives (soft-deletes) a listing. It leaves every storefront query
     * and any cart immediately, but the row stays, so order history, which
     * also carries its own snapshot of the line, is never affected.
     */
    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $product->delete();

        return redirect()->route('seller.products.index')->with('status', 'Listing deleted. Existing orders keep their history.');
    }
}
