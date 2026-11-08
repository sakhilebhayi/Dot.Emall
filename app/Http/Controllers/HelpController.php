<?php

namespace App\Http\Controllers;

use App\Services\Guests\GuestLinkResender;
use App\Services\Ledger\SellerFees;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /** The page for a guest who lost the order e-mail or whose link expired. */
    public function orderLink(): View
    {
        return view('help.order-link');
    }

    /** Always the same answer, so the form says nothing about who has shopped here. */
    public function sendOrderLink(Request $request, GuestLinkResender $resender): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:190']]);

        $resender->send($data['email']);

        return redirect()->route('help.order-link')->with('status', 'If that address was used to place an order here as a guest, the link has just been sent to it. It can take a few minutes. Check your spam folder too.');
    }
}
