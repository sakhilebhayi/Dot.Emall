<?php

namespace App\Http\Controllers\Seller;

use App\Enums\ShippingMethodType;
use App\Models\ShippingMethod;
use App\Models\ShippingMethodRegion;
use App\Services\Addresses\AddressRules;
use App\Services\Shipping\Region;
use App\Services\Shipping\ShippingQuote;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A store's shipping methods and the region rules on them, with a live "what a buyer in Gauteng would see" quote read through the
 * same ShippingQuote the cart uses, so what the seller previews is what the buyer is shown.
 */
class ShippingController extends SellerController
{
    public const MAX_REGIONS = 40;

    public function index(Request $request): View
    {
        $store = $this->myStore($request);

        $methods = $store->shippingMethods()->with('regions')->get();

        $preview = $request->validate([
            'province' => ['nullable', Rule::in(AddressRules::PROVINCES)],
            'postal_code' => ['nullable', 'regex:/^[0-9]{4}$/'],
            'basket' => 'nullable|numeric|min:0|max:'.\App\Http\Controllers\SellerProductController::MAX_PRICE,
        ]);

        $province = $preview['province'] ?? 'Gauteng';
        $basketCents = Money::toCents($preview['basket'] ?? 500);
        $quote = ShippingQuote::for([$store->id => $basketCents], new Region('ZA', $province, $preview['postal_code'] ?? null))[$store->id] ?? null;

        return view('seller.shipping.index', [
            'store' => $store,
            'methods' => $methods,
            'provinces' => AddressRules::PROVINCES,
            'types' => ShippingMethodType::cases(),
            'quote' => $quote,
            'previewProvince' => $province,
            'previewPostal' => $preview['postal_code'] ?? '',
            'previewBasket' => Money::fromCents($basketCents),
            'requireMethods' => (bool) config('emall.shipping.require_methods'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->myStore($request);
        Gate::authorize('create', [ShippingMethod::class, $store]);

        $data = $this->validated($request);

        if ($store->shippingMethods()->count() >= 12) {
            return back()->withErrors(['name' => 'A store can have up to 12 shipping methods.'])->withInput();
        }

        $store->shippingMethods()->create($data + ['position' => (int) $store->shippingMethods()->max('position') + 1]);

        return redirect()->route('seller.shipping')->with('status', 'Shipping method added.');
    }

    public function update(Request $request, ShippingMethod $method): RedirectResponse
    {
        Gate::authorize('update', $method);

        $method->update($this->validated($request));

        return redirect()->route('seller.shipping')->with('status', 'Shipping method updated.');
    }

    public function destroy(ShippingMethod $method): RedirectResponse
    {
        Gate::authorize('delete', $method);

        // Orders keep their own copy of the method's name, price and dates (order_groups), so deleting one changes no order.
        $method->delete();

        return redirect()->route('seller.shipping')->with('status', 'Shipping method deleted. Orders already placed keep what the buyer was shown.');
    }

    public function addRegion(Request $request, ShippingMethod $method): RedirectResponse
    {
        Gate::authorize('update', $method);

        $data = $request->validate([
            'province' => ['nullable', Rule::in(AddressRules::PROVINCES)],
            'postal_from' => ['nullable', 'regex:/^[0-9]{4}$/', 'required_with:postal_to'],
            'postal_to' => ['nullable', 'regex:/^[0-9]{4}$/', 'required_with:postal_from'],
            'price' => 'nullable|numeric|decimal:0,2|min:0|max:'.\App\Http\Controllers\SellerProductController::MAX_PRICE,
        ], [
            'postal_from.regex' => 'A postal code has four digits.',
            'postal_to.regex' => 'A postal code has four digits.',
            'postal_from.required_with' => 'Give both ends of the range, or neither.',
            'postal_to.required_with' => 'Give both ends of the range, or neither.',
        ]);

        if (blank($data['province'] ?? null) && blank($data['postal_from'] ?? null)) {
            return back()->withErrors(['province' => 'Choose a province or a postal code range for this rule.'])->withInput();
        }

        if (filled($data['postal_from'] ?? null) && (int) $data['postal_from'] > (int) $data['postal_to']) {
            return back()->withErrors(['postal_from' => 'The first postal code must not be higher than the last.'])->withInput();
        }

        if ($method->regions()->count() >= self::MAX_REGIONS) {
            return back()->withErrors(['province' => 'A method can have up to '.self::MAX_REGIONS.' region rules.'])->withInput();
        }

        $method->regions()->create([
            'country' => 'ZA',
            'province' => filled($data['province'] ?? null) ? $data['province'] : null,
            'postal_from' => $data['postal_from'] ?? null,
            'postal_to' => $data['postal_to'] ?? null,
            'price' => filled($data['price'] ?? null) ? $data['price'] : null,
        ]);

        return redirect()->route('seller.shipping')->with('status', 'Region rule added. With rules on a method it is offered only in those regions.');
    }

    public function removeRegion(ShippingMethod $method, ShippingMethodRegion $region): RedirectResponse
    {
        Gate::authorize('update', $method);

        $region->delete();

        return redirect()->route('seller.shipping')->with('status', 'Region rule removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'type' => ['required', Rule::enum(ShippingMethodType::class)],
            'price' => 'nullable|numeric|decimal:0,2|min:0|max:'.\App\Http\Controllers\SellerProductController::MAX_PRICE,
            'free_over' => 'nullable|numeric|decimal:0,2|min:0.01|max:'.\App\Http\Controllers\SellerProductController::MAX_PRICE,
            'handling_days' => 'required|integer|min:0|max:30',
            'window_min_days' => 'required|integer|min:0|max:60',
            'window_max_days' => 'required|integer|min:0|max:90|gte:window_min_days',
            'collection_address' => 'nullable|string|max:500',
            'instructions' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ], [
            'window_max_days.gte' => 'The longest time cannot be shorter than the shortest.',
        ]);

        $type = ShippingMethodType::from($data['type']);

        if ($type === ShippingMethodType::FreeOver && blank($data['free_over'] ?? null)) {
            abort(back()->withErrors(['free_over' => 'Say how much a buyer must spend with you for delivery to be free.'])->withInput());
        }

        if ($type === ShippingMethodType::Collection && blank($data['collection_address'] ?? null)) {
            abort(back()->withErrors(['collection_address' => 'Give the address and opening hours buyers collect from.'])->withInput());
        }

        if ($type !== ShippingMethodType::Flat && $type !== ShippingMethodType::FreeOver) {
            $data['price'] = 0;
        }

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'price' => $data['price'] ?? 0,
            'free_over' => $type === ShippingMethodType::FreeOver ? $data['free_over'] : null,
            'handling_days' => $data['handling_days'],
            'window_min_days' => $data['window_min_days'],
            'window_max_days' => $data['window_max_days'],
            'collection_address' => $type === ShippingMethodType::Collection ? $data['collection_address'] : null,
            'instructions' => $data['instructions'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
