<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * The plain page a person gets when their company does not hold Dot.Emall (R-50): at sign-in, when InfoDot says no
 * company of theirs is entitled, and during a session, when the current company's access has ended. Worded from
 * the reason InfoDot gave (a redeem `access` reason or a read-endpoint `status`); it shows nothing from the person's store or account.
 */
class EntitlementRefusal
{
    /**
     * @param  iterable<int, object>  $otherCompanies  the person's other companies here, to switch to
     */
    public static function response(string $reason, ?string $company, iterable $otherCompanies = []): Response
    {
        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/');

        return response()
            ->view('entitlement.refused', [
                ...self::wording($reason, $company),
                'infodotUrl' => $infodot,
                'platformsUrl' => $infodot.'/platforms/'.config('ecosystem.infodot.platform', 'emall'),
                'otherCompanies' => collect($otherCompanies)->values(),
            ], 403)
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @return array{stamp: string, title: string, body: string}
     */
    public static function wording(string $reason, ?string $company): array
    {
        $for = filled($company) ? $company : 'your company';

        $kept = ' Your orders stay open, and the shop stays open to browse.';

        return match ($reason) {
            'suspended' => [
                'stamp' => 'Suspended',
                'title' => "Dot.Emall is suspended for {$for}",
                'body' => 'Selling and the account pages stay closed until the suspension is lifted. Platforms in InfoDot shows what is needed.'.$kept,
            ],
            'payment_required' => [
                'stamp' => 'Payment needed',
                'title' => "Dot.Emall needs a payment for {$for}",
                'body' => 'Selling and the account pages open again once the payment is settled. An owner or administrator can settle it from Platforms in InfoDot.'.$kept,
            ],
            'subscription_expired', 'trial_ended', 'expired', 'cancelled' => [
                'stamp' => 'Ended',
                'title' => "Dot.Emall has ended for {$for}",
                'body' => 'The company no longer holds this platform. Its store and listings are kept as they are. An owner or administrator can take it up again from Platforms in InfoDot.'.$kept,
            ],
            'activation_pending' => [
                'stamp' => 'Not yet on',
                'title' => "Dot.Emall is waiting to be switched on for {$for}",
                'body' => 'The request is in and has not been completed. Platforms in InfoDot shows where it stands.'.$kept,
            ],
            'not_permitted_for_you' => [
                'stamp' => 'Not on your list',
                'title' => "{$for} has not given you Dot.Emall",
                'body' => 'The company holds this platform and chooses who uses it. An owner or administrator can add you from Platforms in InfoDot.'.$kept,
            ],
            'no_personal_access', 'company_required' => [
                'stamp' => 'Company needed',
                'title' => 'Dot.Emall needs a company that has switched it on',
                'body' => 'A personal workspace does not hold selling or the account pages. Add or join a company in InfoDot and switch Dot.Emall on for it from Platforms. Nothing in this workspace has been removed.'.$kept,
            ],
            'temporarily_unavailable', 'not_launched' => [
                'stamp' => 'Unavailable',
                'title' => 'Dot.Emall is not available right now',
                'body' => 'InfoDot is not handing anyone over to this platform at the moment. Platforms in InfoDot shows when it is back.'.$kept,
            ],
            default => [
                'stamp' => 'Not switched on',
                'title' => "Dot.Emall is not switched on for {$for}",
                'body' => 'An owner or administrator can switch it on from Platforms in InfoDot. Anything already in the store is kept as it is.'.$kept,
            ],
        };
    }
}
