<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * /dashboard is only a doorway (W5, D-91): a seller goes to the seller desk (seller.dashboard, W6), everyone else to their account overview
 * (account.home). The old page that used to live here is gone; the seller's numbers are SellerDashboard (also what Dot.Analytics is told).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return $request->user()->stores()->exists()
            ? redirect()->route('seller.dashboard')
            : redirect()->route('account.home');
    }
}
