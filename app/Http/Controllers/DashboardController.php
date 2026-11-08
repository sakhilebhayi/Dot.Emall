<?php

namespace App\Http\Controllers;

use App\Services\SellerDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SellerDashboard $dashboard): View|RedirectResponse
    {
        // /dashboard is only a doorway: a seller goes to the seller desk, everyone else to their account overview. The seller desk itself
        // (seller.dashboard) is the page below.
        if ($request->routeIs('dashboard')) {
            return $request->user()->stores()->exists()
                ? redirect()->route('seller.dashboard')
                : redirect()->route('account.home');
        }

        return view('dashboard', $dashboard->summary($request->user()));
    }
}
