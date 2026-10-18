<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller-managed promo codes -- store-scoped (a shop's own coupon), not a
 * marketplace-wide discount, matching how everything else a seller manages
 * (Store -> Product) is scoped to their own store only. Applied at
 * checkout in CartController::checkout().
 */
class PromoCodeController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        $promoCodes = $store->promoCodes()->latest()->get();

        return view('promo-codes.index', compact('store', 'promoCodes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('seller.store.create');
        }

        // Codes are matched case-insensitively and kept upper-case, so
        // "summer10" and "SUMMER10" are the same code for uniqueness too.
        $request->merge(['code' => Str::upper((string) $request->input('code'))]);

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:40', 'alpha_dash',
                // Unique within this store only: another store may run the same code.
                Rule::unique('promo_codes', 'code')->where('store_id', $store->id),
                fn ($attribute, $value, $fail) => $store->promoCodes()->whereRaw('UPPER(code) = ?', [$value])->exists()
                    ? $fail('You already have a promo code with that name.')
                    : null,
            ],
            'discount_type' => ['required', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($validated['discount_type'] === 'percent' && $validated['discount_value'] > 100) {
            return back()->withErrors(['discount_value' => 'A percentage discount cannot exceed 100.'])->withInput();
        }

        $store->promoCodes()->create([
            'code' => $validated['code'],
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'max_uses' => $validated['max_uses'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return redirect()->route('seller.promo-codes.index')->with('status', 'Promo code created.');
    }

    public function destroy(PromoCode $promoCode): RedirectResponse
    {
        Gate::authorize('delete', $promoCode);

        $promoCode->delete();

        return redirect()->route('seller.promo-codes.index')->with('status', 'Promo code deleted.');
    }
}
