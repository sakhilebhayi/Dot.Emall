<?php

namespace App\Http\Controllers;

use App\Services\Paystack\FakePaystackClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * TESTING ONLY (routes exist only in local and testing with EMALL_E2E_FAKE_PAYSTACK on): the page a buyer lands on instead of
 * Paystack's. Pay, Fail and Abandon record an outcome for FakePaystackClient::verifyTransaction and send the buyer to the same
 * callback address Paystack would, so the real return handling is what gets exercised.
 */
class DevFakePaystackController extends Controller
{
    public function show(string $reference): View
    {
        $state = Cache::get(FakePaystackClient::CACHE_PREFIX.$reference);
        abort_unless(is_array($state), 404);

        return view('checkout.fake-paystack', ['reference' => $reference, 'state' => $state]);
    }

    public function complete(Request $request, string $reference): RedirectResponse
    {
        $state = Cache::get(FakePaystackClient::CACHE_PREFIX.$reference);
        abort_unless(is_array($state), 404);

        $outcome = $request->validate(['outcome' => ['required', 'in:success,failed,abandoned']])['outcome'];
        $state['status'] = $outcome === 'abandoned' ? 'pending' : $outcome;
        Cache::put(FakePaystackClient::CACHE_PREFIX.$reference, $state, now()->addHours(3));

        $glue = str_contains($state['callback'], '?') ? '&' : '?';

        return redirect()->away($state['callback'].$glue.'reference='.rawurlencode($reference));
    }
}
