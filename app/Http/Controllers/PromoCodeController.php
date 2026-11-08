<?php

namespace App\Http\Controllers;

use App\Models\Order;
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
            return redirect()->route('sell.start');
        }

        $promoCodes = $store->promoCodes()->latest()->get();

        // The real redemptions: orders that carried one of these codes (not the buyer's name: a seller does not need it for a coupon).
        $usage = Order::query()->whereIn('promo_code_id', $promoCodes->pluck('id'))->where('status', '!=', 'cancelled')
            ->withCount(['items as store_items' => fn ($q) => $q->where('store_id', $store->id)])
            ->latest('id')->limit(30)->get(['id', 'promo_code', 'promo_code_id', 'discount_amount', 'promo_free_shipping', 'created_at', 'currency', 'status']);

        $redeemed = Order::query()->whereIn('promo_code_id', $promoCodes->pluck('id'))->where('status', '!=', 'cancelled')
            ->selectRaw('promo_code_id, count(*) as uses, sum(discount_amount) as given')->groupBy('promo_code_id')->get()->keyBy('promo_code_id');

        return view('seller.promo-codes.index', compact('store', 'promoCodes', 'usage', 'redeemed'));
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $request->user()->store;

        if (! $store) {
            return redirect()->route('sell.start');
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
            // decimal(10,2) column; a third decimal used to be rounded away, a larger number was a 500 on PostgreSQL.
            'discount_value' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.SellerProductController::MAX_PRICE],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:'.SellerProductController::MAX_STOCK],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'free_shipping' => ['nullable', 'boolean'],
            'min_spend' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:'.SellerProductController::MAX_PRICE],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'conditions' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['discount_type'] === 'percent' && $validated['discount_value'] > 100) {
            return back()->withErrors(['discount_value' => 'A percentage discount cannot exceed 100.'])->withInput();
        }

        $store->promoCodes()->create([
            'code' => $validated['code'],
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'max_uses' => $validated['max_uses'] ?? null,
            // A date means the end of that day, so "expires on the 30th" still works all day on the 30th.
            'expires_at' => filled($validated['expires_at'] ?? null) ? \Illuminate\Support\Carbon::parse($validated['expires_at'])->endOfDay() : null,
            'free_shipping' => (bool) ($validated['free_shipping'] ?? false),
            'min_spend' => $validated['min_spend'] ?? null,
            'per_user_limit' => $validated['per_user_limit'] ?? null,
            'conditions' => filled($validated['conditions'] ?? null) ? trim($validated['conditions']) : null,
        ]);

        return redirect()->route('seller.promo-codes.index')->with('status', 'Promo code created.');
    }

    /** Pause or resume a code without losing its history. */
    public function toggle(PromoCode $promoCode): RedirectResponse
    {
        Gate::authorize('delete', $promoCode);

        $promoCode->update(['is_active' => ! $promoCode->is_active]);

        return redirect()->route('seller.promo-codes.index')->with('status', $promoCode->is_active ? 'Promo code switched on.' : 'Promo code paused. Buyers cannot use it until you switch it on.');
    }

    public function destroy(PromoCode $promoCode): RedirectResponse
    {
        Gate::authorize('delete', $promoCode);

        $promoCode->delete();

        return redirect()->route('seller.promo-codes.index')->with('status', 'Promo code deleted.');
    }
}
