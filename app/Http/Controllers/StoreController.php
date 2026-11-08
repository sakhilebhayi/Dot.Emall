<?php

namespace App\Http\Controllers;

use App\Enums\StoreStatus;
use App\Services\Stores\StoreOnboarding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function store(Request $request, StoreOnboarding $onboarding): RedirectResponse
    {
        $existing = auth()->user()->store;

        if ($existing) {
            return redirect()->route('seller.products.create');
        }

        $store = $onboarding->create($request->user(), $request->all());

        return redirect()->route('seller.products.create')
            ->with('status', $store->is_active
                ? 'Your store is live. List your first product below.'
                : 'Your store is saved as a draft. Finish it and submit it for review when you are ready.');
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
    public function update(Request $request, StoreOnboarding $onboarding): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        $request->validate(['is_active' => 'nullable|boolean']);

        // Only a store that is live (active) can be opened or closed by its owner; a draft, pending or suspended store stays
        // closed until the Emall team has decided.
        $onboarding->update($store, $request->only(array_keys($onboarding->rules($store))) + ['name' => $store->name]);

        if ($store->status === StoreStatus::Active) {
            $store->update(['is_active' => $request->boolean('is_active')]);
        }

        return redirect()->route('seller.store.edit')->with('status', $store->is_active ? 'Store updated.' : 'Store updated and closed: your listings are hidden from the marketplace until you reopen it.');
    }
}
