<?php

namespace App\Http\Controllers;

use App\Services\Ledger\SellerFees;
use Illuminate\View\View;

/**
 * Help pages. The text states what the platform does today (delivery estimates are the seller's, returns run 7 days from delivery,
 * payments go through Paystack, accounts come from InfoDot) and takes every number from configuration, so it cannot drift from
 * the behaviour. A contact channel is shown only when one is configured: none is invented.
 */
class HelpController extends Controller
{
    public function index(): View
    {
        return view('help.index');
    }

    public function delivery(): View
    {
        return view('help.delivery');
    }

    public function returns(): View
    {
        return view('help.returns', [
            'days' => (int) config('emall.returns.window_days', 7),
            'respondDays' => (int) config('emall.returns.seller_response_days', 10),
        ]);
    }

    public function contact(): View
    {
        return view('help.contact', ['email' => trim((string) config('emall.support_email'))]);
    }

    public function sellers(): View
    {
        return view('help.sellers', ['fees' => SellerFees::text(null)]);
    }
}
