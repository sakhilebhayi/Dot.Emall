<?php

namespace App\Http\Controllers\Seller;

use App\Services\Dashboard\SellerHome;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The seller dashboard (R-51): the same page and the same data class as `dashboard` for a person with a store. */
class DashboardController extends SellerController
{
    public function __invoke(Request $request, SellerHome $home): View
    {
        return view('seller.dashboard', $home->data($request->user(), $this->myStore($request), $request->query('period')));
    }
}
