<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\BuyerHome;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The buyer's overview. NOT a seller dashboard: recent orders, returns that need the buyer, what they saved, alerts, searches and their
 * default address. Sellers reach their desk from seller.dashboard; /dashboard sends each person to the right one.
 */
class AccountController extends Controller
{
    public function home(Request $request, BuyerHome $home): View
    {
        return view('account.dashboard', $home->data($request->user()));
    }
}
