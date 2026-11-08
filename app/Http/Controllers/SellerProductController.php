<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Catalogue\CategoryTree;
use App\Services\Catalogue\Inventory;
use App\Services\Catalogue\ProductAttributes;
use App\Services\Catalogue\ProductVariants;
use App\Services\ProductImages;
use App\Services\Seller\CategoryOptions;
use App\Services\Seller\FeePreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public const PER_PAGE = 20;

    public function index(Request $request, CategoryTree $tree, CategoryOptions $options): View|RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::in(['approved', 'pending_approval', 'rejected', 'off'])],
            'stock' => ['nullable', Rule::in(['in', 'low', 'out'])],
            'category' => 'nullable|integer',
        ]);

        $query = Product::query()->where('store_id', $store->id);

        if (filled($filters['q'] ?? null)) {
            $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$needle])->orWhereRaw('LOWER(sku) LIKE ?', [$needle]));
        }

        match ($filters['status'] ?? null) {
            'approved', 'pending_approval', 'rejected' => $query->where('status', $filters['status']),
            'off' => $query->where('is_active', false),
            default => null,
        };

        match ($filters['stock'] ?? null) {
            'out' => $query->where('stock', '<=', 0),
            'low' => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold'),
            'in' => $query->where('stock', '>', 0),
            default => null,
        };

        if (filled($filters['category'] ?? null)) {
            $query->whereIn('category_id', $tree->withDescendants((int) $filters['category']));
        }

        $products = $query->with(['images' => fn ($q) => $q->where('sort_order', 0), 'category:id,name'])
            ->withCount(['variants as active_variants_count' => fn ($q) => $q->where('is_active', true)])
            ->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        $counts = Product::query()->where('store_id', $store->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('seller.products.index', [
            'store' => $store,
            'products' => $products,
            'filters' => $filters,
            'categories' => $options->flat(),
            'counts' => $counts,
            'total' => (int) $counts->sum(),
        ]);
    }

    public function create(Request $request, CategoryOptions $options, FeePreview $fees): View|RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        return view('seller.products.create', [
            'store' => $store,
            'categories' => $options->flat(),
            'brands' => Brand::query()->orderBy('name')->limit(500)->pluck('name'),
            'fee' => $fees->for($store, 0),
        ]);
    }

    public function store(Request $request, ProductImages $images): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        Gate::authorize('create', [Product::class, $store]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|decimal:0,2|min:0|max:'.self::MAX_PRICE,
            'stock' => 'required|integer|min:0|max:'.self::MAX_STOCK,
            'sku' => 'nullable|string|max:100|unique:products,sku|unique:product_variants,sku',
            'category_id' => ['nullable', Rule::exists('store_categories', 'id')->where('is_active', true)],
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
                'stock' => $validated['stock'],
                'sku' => $validated['sku'] ?? null,
                'brand_id' => filled($validated['brand'] ?? null) ? Brand::named($validated['brand'])->id : null,
                'low_stock_threshold' => $validated['low_stock_threshold'] ?? 5,
                'status' => 'pending_approval',
            ]);

            // Decodes every photo before writing any: a bad one aborts the whole listing rather than leaving it half-created.
            $images->add($product, $request->file('images', []));

            return $product;
        });

        return redirect()->route('seller.products.edit', $product)
            ->with('status', 'Listing saved and sent for review. Add its specifications, options and photos below while you wait.');
    }

    public function edit(Request $request, Product $product, CategoryOptions $options, ProductAttributes $attributes, FeePreview $fees): View
    {
        Gate::authorize('update', $product);

        $product->load(['images', 'variants', 'options', 'attributeValues', 'category', 'brand']);
        $store = $product->store;

        // The form is pre-filled with today's stock. Remember that number, so update() can tell "the seller changed the
        // stock" from "the seller left it alone while buyers bought some of it".
        session([self::stockSeenKey($product) => (int) $product->stock]);

        $definitions = $attributes->definitionsFor($product->category);
        $simple = $this->isSimple($product);

        return view('seller.products.edit', [
            'product' => $product,
            'store' => $store,
            'categories' => $options->flat(),
            'brands' => Brand::query()->orderBy('name')->limit(500)->pluck('name'),
            'definitions' => $definitions,
            'values' => $product->attributeValues->keyBy('key'),
            'simple' => $simple,
            'combos' => $simple ? [] : $this->combinations($product),
            'fee' => $fees->for($store, $product->price),
            'movements' => StockMovement::query()->where('product_id', $product->id)->latest('id')->limit(5)->get(),
        ]);
    }

    /**
     * Editing does not reset an approved listing back to pending_approval -- re-review on every price/stock tweak would
     * make routine restocking unusable, and moderation here only ever gated the original listing.
     */
    public function update(Product $product, Request $request, Inventory $inventory): RedirectResponse
    {
        Gate::authorize('update', $product);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|decimal:0,2|min:0|max:'.self::MAX_PRICE,
            'stock' => 'required|integer|min:0|max:'.self::MAX_STOCK,
            // The stock number the edit form was drawn with (a hidden field). Sturdier than the session: it survives an
            // expired session and two tabs.
            'stock_was' => 'nullable|integer|min:0|max:'.self::MAX_STOCK,
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku,'.$product->id, Rule::unique('product_variants', 'sku')->where(fn ($q) => $q->where('product_id', '!=', $product->id))],
            'category_id' => ['nullable', Rule::exists('store_categories', 'id')->where('is_active', true)],
            'brand' => 'nullable|string|max:100',
            'low_stock_threshold' => 'nullable|integer|min:0|max:'.self::MAX_STOCK,
        ]);

        $adjusted = DB::transaction(function () use ($product, $validated, $request, $inventory) {
            // The row lock serialises this with checkout, which decrements stock under the same lock (products first,
            // then variants, always in that order).
            $current = Product::query()->lockForUpdate()->findOrFail($product->id);

            $simple = $this->isSimple($current);
            $variant = $simple ? ProductVariant::query()->where('product_id', $current->id)->lockForUpdate()->first() : null;

            $seen = isset($validated['stock_was']) ? (int) $validated['stock_was'] : $this->stockSeenFromSession($current);

            $values = [
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'sku' => $simple ? ($validated['sku'] ?? null) : $current->sku,
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

        return redirect()->route('seller.products.edit', $product)->with('status', $adjusted === null
            ? 'Listing updated.'
            : "Listing updated. Stock is {$adjusted}, not the number on the form, because units were sold while you were editing.");
    }

    /** The specifications the category asks for. Replaces the product's set; a required one left empty is refused with its name. */
    public function attributes(Request $request, Product $product, ProductAttributes $attributes): RedirectResponse
    {
        Gate::authorize('update', $product);
        $request->validate(['attributes' => 'nullable|array', 'attributes.*' => 'nullable|string|max:255']);

        try {
            $attributes->sync($product->load('category'), (array) $request->input('attributes', []));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('seller.products.edit', $product)->withFragment('specs')->with('status', 'Specifications saved.');
    }

    public function toggleActive(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        $product->update(['is_active' => ! $product->is_active]);

        return redirect()->route('seller.products.index')
            ->with('status', $product->is_active ? 'Listing reactivated.' : 'Listing deactivated.');
    }

    /** Switch several of the seller's own listings on or off at once. Ids that are not theirs are ignored, not an error to probe with. */
    public function bulk(Request $request): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate'])],
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
        ], ['ids.required' => 'Tick the listings you want to change first.', 'ids.min' => 'Tick the listings you want to change first.']);

        $on = $data['action'] === 'activate';
        $changed = 0;

        // One by one, so the search index and category counts hear about each change.
        Product::query()->where('store_id', $store->id)->whereIn('id', $data['ids'])->where('is_active', ! $on)->get()
            ->each(function (Product $p) use ($on, &$changed) {
                $p->update(['is_active' => $on]);
                $changed++;
            });

        return redirect()->route('seller.products.index', $request->only(['q', 'status', 'stock', 'category', 'page']))
            ->with('status', $changed === 0 ? 'Nothing changed: those listings were already '.($on ? 'on' : 'off').'.' : $changed.' '.Str::plural('listing', $changed).($on ? ' switched on.' : ' switched off.'));
    }

    /** A copy to start from: same description, category, brand, options and prices; no photos, no stock, switched off and sent for review. */
    public function duplicate(Request $request, Product $product, ProductVariants $variants, ProductAttributes $attributes): RedirectResponse
    {
        Gate::authorize('update', $product);
        $product->load(['variants', 'options', 'attributeValues', 'category']);

        $copy = DB::transaction(function () use ($product, $variants, $attributes) {
            $copy = Product::create([
                'store_id' => $product->store_id,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'name' => Str::limit('Copy of '.$product->name, 255, ''),
                'slug' => Str::slug($product->name).'-'.Str::random(6),
                'description' => $product->description,
                'price' => $product->price,
                'stock' => 0,
                'sku' => null,
                'low_stock_threshold' => $product->low_stock_threshold,
                'status' => 'pending_approval',
                'is_active' => false,
            ]);

            if ($product->options->isNotEmpty()) {
                $variants->defineOptions($copy, $product->options->mapWithKeys(fn ($o) => [$o->name => $o->values])->all());
                foreach ($product->variants->where('is_active', true)->filter(fn ($v) => $v->options) as $v) {
                    $variants->saveVariant($copy, $v->options, ['price' => $v->price, 'is_active' => true, 'sku' => null]);
                }
            }

            try {
                $attributes->sync($copy->load('category'), $product->attributeValues->mapWithKeys(fn ($a) => [$a->key => $a->value])->all());
            } catch (ValidationException) {
                // A spec the category no longer takes is not copied; the seller sees what is missing on the edit page.
            }

            return $copy;
        });

        return redirect()->route('seller.products.edit', $copy)->with('status', 'Copy made. It has no photos or stock yet and is switched off until you turn it on.');
    }

    /** A listing the operator rejected goes back for review once the seller has fixed what the reason said. */
    public function resubmit(Product $product): RedirectResponse
    {
        Gate::authorize('update', $product);

        if ($product->status !== 'rejected') {
            return back()->withErrors(['status' => 'Only a rejected listing can be sent again.']);
        }

        $product->update(['status' => 'pending_approval', 'rejected_reason' => null]);

        return redirect()->route('seller.products.edit', $product)->with('status', 'Sent for review again.');
    }

    public function stockHistory(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('seller.products.stock-history', [
            'product' => $product,
            'movements' => StockMovement::query()->where('product_id', $product->id)->with(['variant:id,options', 'actor:id,name'])->latest('id')->paginate(25),
        ]);
    }

    /**
     * Archives (soft-deletes) a listing. It leaves every storefront query and any cart immediately, but the row stays, so
     * order history, which also carries its own snapshot of the line, is never affected.
     */
    public function destroy(Product $product): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $product->delete();

        return redirect()->route('seller.products.index')->with('status', 'Listing deleted. Existing orders keep their history.');
    }

    /** A product is simple when it has no options: one implicit variant that mirrors the price and stock on the product. */
    private function isSimple(Product $product): bool
    {
        return ! $product->options()->exists();
    }

    /**
     * Every combination of the product's option values, each with the variant that already has it (or null).
     *
     * @return array<int, array{choices: array<string, string>, variant: ?ProductVariant}>
     */
    private function combinations(Product $product): array
    {
        $existing = $product->variants->keyBy('option_key');
        $combos = [[]];

        foreach ($product->options as $option) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($option->values as $value) {
                    $next[] = $combo + [$option->name => $value];
                }
            }
            $combos = $next;
        }

        return array_map(fn (array $choices) => ['choices' => $choices, 'variant' => $existing[ProductVariant::keyFor($choices)] ?? null], array_slice($combos, 0, ProductVariants::MAX_VARIANTS));
    }

    /** Without the hidden `stock_was` field the form's number is remembered in the session (see edit()). */
    private function stockSeenFromSession(Product $current): ?int
    {
        $seen = session()->pull(self::stockSeenKey($current));

        return is_int($seen) ? $seen : null;
    }

    private static function stockSeenKey(Product $product): string
    {
        return 'stock_seen.'.$product->id;
    }
}
