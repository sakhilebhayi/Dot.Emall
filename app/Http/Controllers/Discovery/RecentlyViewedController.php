<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\RecommendedItem;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** What this browser (or this account) looked at lately. Private to the person: a keyed hash of the account or of the browser's session token, never an e-mail. */
class RecentlyViewedController extends Controller
{
    public function index(Request $request, RecentlyViewed $viewed): View
    {
        $products = $viewed->for(Actor::forRequest($request), (int) config('discovery.views.keep_per_person', 20));

        return view('discover.recently-viewed', [
            'items' => $products->map(fn ($p) => new RecommendedItem($p, 'You viewed this'))->all(),
            'signedIn' => $request->user() !== null,
        ]);
    }

    public function clear(Request $request, RecentlyViewed $viewed): RedirectResponse
    {
        $viewed->forget(Actor::forRequest($request));

        return redirect()->route('recently-viewed.index')->with('status', 'Your recently viewed list is cleared.');
    }
}
