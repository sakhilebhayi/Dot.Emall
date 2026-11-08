<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the person is told, and how (D-23). Order, payment, delivery and return notices are locked on: the table cannot turn them off.
 * Alerts (back in stock, price drop) are in the app by default and e-mail is opt-in. Marketing is off everywhere until the person says yes.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request, NotificationPreferences $prefs): View
    {
        return view('account.notifications', ['prefs' => $prefs->all($request->user())]);
    }

    public function update(Request $request, NotificationPreferences $prefs): RedirectResponse
    {
        $user = $request->user();

        foreach ([NotificationPreferences::ALERTS, NotificationPreferences::MARKETING] as $category) {
            $prefs->set($user, $category, $request->boolean("{$category}_mail"), $request->boolean("{$category}_in_app"));
        }

        return redirect()->route('account.notifications')->with('status', 'Your notification choices are saved.');
    }
}
