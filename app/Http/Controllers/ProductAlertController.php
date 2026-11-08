<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductAlert;
use App\Services\Discovery\ProductAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Back-in-stock and price-drop alerts: set one from a product page, see and cancel them all on the account alerts page. */
class ProductAlertController extends Controller
{
    public function index(Request $request): View
    {
        $alerts = ProductAlert::query()->where('user_id', $request->user()->id)->with(['product.store', 'product.images'])->orderByDesc('active')->orderByDesc('id')->get();
        $variants = \App\Models\ProductVariant::query()->whereIn('id', $alerts->pluck('variant_id')->filter())->get()->keyBy('id');

        return view('alerts.index', [
            'active' => $alerts->where('active', true)->values(),
            'past' => $alerts->where('active', false)->whereNotNull('triggered_at')->take(10)->values(),
            'variants' => $variants,
            'preferencesUrl' => \App\Support\Shell::first(['account.notifications', 'account.preferences', 'notifications.preferences', 'profile.show'])['url'],
        ]);
    }

    public function store(Request $request, Product $product, ProductAlerts $alerts): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:'.ProductAlert::BACK_IN_STOCK.','.ProductAlert::PRICE_DROP],
            'variant_id' => ['nullable', 'integer'],
            'target_price' => ['nullable', 'numeric', 'min:0.01', 'max:10000000'],
        ]);

        try {
            $alerts->subscribe($request->user(), $product, $data['kind'], isset($data['target_price']) ? (float) $data['target_price'] : null, $data['variant_id'] ?? null);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors(), 'alert');
        }

        return back()->with('status', $data['kind'] === ProductAlert::BACK_IN_STOCK
            ? 'We will tell you once, in the app, when it is back in stock.'
            : 'We will tell you if the price falls below what it is now.');
    }

    public function destroy(Request $request, ProductAlert $alert, ProductAlerts $alerts): RedirectResponse
    {
        Gate::authorize('update', $alert);

        $alerts->cancel($alert);

        return back()->with('status', 'Alert cancelled.');
    }
}
