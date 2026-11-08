<?php

namespace App\Http\Controllers\Seller;

use App\Services\Seller\SellerAnalytics;
use App\Services\Seller\SellerExports;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends SellerController
{
    public function index(Request $request, SellerAnalytics $analytics): View
    {
        $store = $this->myStore($request);

        return view('seller.analytics', ['store' => $store, 'periods' => SellerAnalytics::PERIODS] + $analytics->report($store, $request->query('period')));
    }

    /** CSV of this store's own order lines or products for the chosen period. Nothing about a buyer is in it. */
    public function export(Request $request, string $what, SellerAnalytics $analytics, SellerExports $exports): StreamedResponse
    {
        $store = $this->myStore($request);
        abort_unless(in_array($what, ['orders', 'products'], true), 404);

        $w = $analytics->window($request->query('period'));

        return match ($what) {
            'orders' => $exports->orders($store, $w['from'], $w['to']),
            'products' => $exports->products($store, $w['from'], $w['to']),
        };
    }
}
