<?php

namespace App\Http\Controllers\Seller;

use App\Services\Ledger\LedgerService;
use App\Services\Seller\SellerAnalytics;
use App\Services\Seller\SellerDesk;
use App\Services\Stores\StoreTrust;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends SellerController
{
    public function __invoke(Request $request, SellerDesk $desk, SellerAnalytics $analytics, LedgerService $ledger): View
    {
        $store = $this->myStore($request);
        $window = $analytics->window($request->query('period'));

        return view('seller.dashboard', [
            'store' => $store,
            'needs' => $desk->needsYou($store, $request->user()),
            'window' => $window,
            'periods' => SellerAnalytics::PERIODS,
            'sales' => $analytics->sales($store, $window['from'], $window['to']),
            'previous' => $analytics->sales($store, $window['prevFrom'], $window['prevTo']),
            'fulfilment' => $analytics->fulfilment($store, $window['from'], $window['to']),
            'balance' => $ledger->balance($request->user()),
            'recent' => $desk->recentParcels($store),
            'reviews' => $desk->reviewSummary($store),
            'responses' => $desk->responseTimes($store),
            'trust' => StoreTrust::for($store),
        ]);
    }
}
