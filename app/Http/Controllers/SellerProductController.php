<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    public function create(): View|RedirectResponse
    {
        $store = Store::where('owner_id', auth()->id())->first();

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        $categories = StoreCategory::orderBy('name')->get();

        return view('products.create', ['store' => $store, 'categories' => $categories]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = Store::where('owner_id', auth()->id())->first();

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        Gate::authorize('create', [Product::class, $store]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0',
            'compare_price' => 'nullable|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'sku' => 'nullable|string|unique:products,sku',
            'category_id' => 'nullable|exists:store_categories,id',
        ]);

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
            'status' => 'pending_approval',
        ]);

        return redirect()->route('products.show', $product)
            ->with('status', 'Listing submitted. It will appear in the marketplace once approved.');
    }
}
