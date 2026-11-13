<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\Orders\OrderPage;
use App\Services\Seller\SellerDesk;
use App\Support\Landing;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /dashboard is where a person arrives after signing in through InfoDot (R-50). It used to be a doorway that only redirected (W5, D-91);
 * it is now the landing: the Dot.Emall logo, a welcome, what the product is for and ONE primary action for the role that arrived.
 * A seller gets the seller landing inside the seller desk, everyone else the buyer landing inside their account. The pages the doorway
 * used to send people to are unchanged and one link away: seller.dashboard (the desk's numbers) and account.home (the account overview).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, OrderPage $page, SellerDesk $desk): View
    {
        $user = $request->user();
        $store = $user->store;

        return $store instanceof Store
            ? view('landing.seller', Landing::seller($user, $store, $desk))
            : view('landing.buyer', Landing::buyer($user, $page));
    }
}
