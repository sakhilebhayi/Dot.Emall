<?php

namespace App\Http\Controllers;

use App\Services\SellerDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SellerDashboard $dashboard): View
    {
        return view('dashboard', $dashboard->summary($request->user()));
    }
}
