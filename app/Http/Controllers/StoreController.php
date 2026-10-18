<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StoreController extends Controller
{
    /**
     * "Become a seller." A Store activates immediately on creation --
     * becoming a seller is identity, not risk; only individual Product
     * listings are gated (see SellerProductController).
     */
    public function create(): View|RedirectResponse
    {
        $existing = auth()->user()->store;

        if ($existing) {
            return redirect()->route('seller.products.create');
        }

        return view('stores.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $existing = auth()->user()->store;

        if ($existing) {
            return redirect()->route('seller.products.create');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        Store::create([
            'owner_id' => auth()->id(),
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('seller.products.create')
            ->with('status', 'Your store is live. List your first product below.');
    }

    /**
     * Edit the signed-in user's one store. There is no store id in the URL on
     * purpose: a user has exactly one store (see User::store()), so there is
     * nothing to guess and nothing to tamper with.
     */
    public function edit(Request $request): View|RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        return view('stores.edit', compact('store'));
    }

    /**
     * The slug is left alone when the name changes: it is part of the
     * store's public identity and renaming should not break shared links.
     * Order history is unaffected either way (lines carry their own
     * snapshot of the store name).
     */
    public function update(Request $request): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $store->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('seller.store.edit')->with('status', $store->is_active ? 'Store updated.' : 'Store updated and closed: your listings are hidden from the marketplace until you reopen it.');
    }
}
