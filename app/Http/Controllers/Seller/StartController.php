<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Ledger\SellerFees;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Sell on Emall": what selling involves, said before anyone is asked for anything. Public: a visitor reads the fees, the
 * returns rule and the payout policy first, and signs in with InfoDot only when they decide to go on.
 */
class StartController extends Controller
{
    public function __invoke(Request $request, SellerFees $fees): View
    {
        $user = $request->user();

        // A visitor who signs in from this page comes back to the first step, not to the shop.
        if ($user === null) {
            $request->session()->put('url.intended', route('seller.store.create'));
        }

        return view('seller.start', [
            'user' => $user,
            'store' => $user?->store,
            'feeSentence' => $fees->disclosure(null),
            'percent' => $fees->percent(null),
            'releaseDays' => (int) config('emall.commission.release_after_days', 7),
            'returnDays' => (int) config('emall.returns.window_days', 7),
            'responseDays' => (int) config('emall.returns.seller_response_days', 10),
            'requiresReview' => (bool) config('emall.seller.requires_review'),
        ]);
    }
}
