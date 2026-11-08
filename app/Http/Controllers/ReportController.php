<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use App\Services\Community\ContentReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Report this listing" and "Report this store": a signed-in person tells the operator; nothing is hidden by a report alone. */
class ReportController extends Controller
{
    public function product(Request $request, Product $product, ContentReports $reports, ReviewActionController $shared): RedirectResponse
    {
        abort_unless(Product::query()->browsable()->whereKey($product->id)->exists(), 404);

        return $shared->file($request, $reports, 'product', $product->id, fn () => redirect()->to(route('products.show', $product).'#report-listing'), 'Thank you. The marketplace team will look at this listing.');
    }

    public function store(Request $request, Store $store, ContentReports $reports, ReviewActionController $shared): RedirectResponse
    {
        abort_unless($store->is_active, 404);

        return $shared->file($request, $reports, 'store', $store->id, fn () => redirect()->to(route('stores.show', $store).'#report-store'), 'Thank you. The marketplace team will look at this store.');
    }
}
