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
}
