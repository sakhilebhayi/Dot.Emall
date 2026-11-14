<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Dashboard\BuyerHome;
use App\Services\Dashboard\SellerHome;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /dashboard is where a person arrives after signing in through InfoDot (R-50), and since R-51 it is the SAME page as the desk home it used
 * to point to: a person with a store gets the seller dashboard (the view `seller.dashboard` also renders), everyone else the buyer dashboard
 * (the view `account.home` also renders). Each pair is fed by one data class, so the two routes cannot drift. Every route name and URL keeps working.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, BuyerHome $buyer, SellerHome $seller): View
    {
        $user = $request->user();
        $store = $user->store;

        return $store instanceof Store
            ? view('seller.dashboard', $seller->data($user, $store, $request->query('period')))
            : view('account.dashboard', $buyer->data($user));
    }
}
