<?php

namespace App\Support;

use App\Services\Addresses\AddressRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The province a shopper says they are in. It is a choice, never a measurement: nothing here asks the browser for a
 * location, and only one of the nine provinces is ever stored (a cookie for a year, and the session). It feeds local
 * discovery (sellers in your province, the "ships from your province" mark) and is the place delivery estimates read the
 * shopper's region from before an address exists.
 */
final class ShopperRegion
{
    public const SESSION_KEY = 'shopper.region';

    public const COOKIE = 'emall_region';

    /** @return list<string> */
    public static function provinces(): array
    {
        return AddressRules::PROVINCES;
    }

    public static function valid(?string $province): ?string
    {
        $province = trim((string) $province);

        foreach (self::provinces() as $known) {
            if (strcasecmp($known, $province) === 0) {
                return $known;
            }
        }

        return null;
    }

    /** The shopper's province: this session's choice, else the remembered cookie, else null (unknown). */
    public static function current(?Request $request = null): ?string
    {
        $request ??= request();

        if ($request->hasSession() && ($fromSession = self::valid($request->session()->get(self::SESSION_KEY)))) {
            return $fromSession;
        }

        $fromCookie = self::valid($request->cookie(self::COOKIE));

        if ($fromCookie !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $fromCookie);
        }

        return $fromCookie;
    }

    /** Remembers the choice (null forgets it). Queues the cookie on the response. */
    public static function remember(Request $request, ?string $province): ?string
    {
        $province = self::valid($province);

        if ($province === null) {
            $request->session()->forget(self::SESSION_KEY);
            Cookie::queue(Cookie::forget(self::COOKIE));

            return null;
        }

        $request->session()->put(self::SESSION_KEY, $province);
        Cookie::queue(Cookie::make(self::COOKIE, $province, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));

        return $province;
    }
}
