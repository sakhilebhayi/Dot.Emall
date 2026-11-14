<?php

namespace App\Support;

use App\Models\Team;
use App\Models\User;

/**
 * What the page a person arrives on after signing in (/dashboard) shows. Everything here is read from that person's own records
 * or from the live catalogue: nothing is estimated, and where there is nothing the view says so. Each role gets ONE primary action. The R-50 rule below
 * is the FALLBACK: since R-51 a state-driven action (parcels to ship, an unpaid order) comes first (Services\Dashboard\SellerHome, BuyerHome):
 *
 *   seller, no product yet      Add your first product   seller.products.create
 *   seller, with products       Manage your products     seller.products.index
 *   buyer, something listed     Shop the mall            products.index
 *   buyer, nothing listed yet   Open a shop              sell.start  (an empty mall has nothing to buy, so shopping it is not offered as the main step)
 */
final class Landing
{
    public const LOGO = 'images/logo.png';

    public const LOGO_ON_DARK = 'images/logo-light.png';

    /** @return array{home: string, platforms: string} */
    public static function infodot(): array
    {
        // The InfoDot host this app really signs in against (the redeem endpoint), so the way back follows the same setting.
        $parts = parse_url((string) config('ecosystem.infodot.redeem_url'));
        $base = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : 'https://infodot.co.za';

        return ['home' => $base.'/home', 'platforms' => $base.'/platforms'];
    }

    public static function firstName(User $user): string
    {
        $parts = preg_split('/\s+/', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts[0] ?? '';
    }

    /** The company this person is working for: an InfoDot company or a team they made, never the personal team every account is given. */
    public static function company(User $user): ?string
    {
        $team = $user->currentTeam;

        if ($team instanceof Team && ! $team->personal_team && filled($team->name)) {
            return (string) $team->name;
        }

        $linked = $user->allTeams()->first(fn ($t) => $t instanceof Team && ! $t->personal_team && filled($t->infodot_team_uuid));

        return $linked instanceof Team ? (string) $linked->name : null;
    }

    /** The R-50 rule for a seller when no state-driven action applies (Services\Dashboard\SellerHome puts "Open orders to ship" ahead of it). @return array{label: string, href: string, route: string, state: string} */
    public static function sellerFallback(bool $hasProducts): array
    {
        return $hasProducts
            ? ['label' => 'Manage your products', 'href' => route('seller.products.index'), 'route' => 'seller.products.index', 'state' => 'fallback']
            : ['label' => 'Add your first product', 'href' => route('seller.products.create'), 'route' => 'seller.products.create', 'state' => 'fallback'];
    }

    /** The R-50 rule for a buyer when no state-driven action applies (Services\Dashboard\BuyerHome puts "See the unpaid order" ahead of it). @return array{label: string, href: string, route: string, state: string} */
    public static function buyerFallback(bool $mallHasProducts): array
    {
        return $mallHasProducts
            ? ['label' => 'Shop the mall', 'href' => route('products.index'), 'route' => 'products.index', 'state' => 'fallback']
            : ['label' => 'Open a shop', 'href' => route('sell.start'), 'route' => 'sell.start', 'state' => 'fallback'];
    }

    /**
     * What every dashboard needs to say hello: the person, their company and the InfoDot host. $company overrides the linked company
     * (a seller is welcomed as the person who runs their store).
     *
     * @return array<string, mixed>
     */
    public static function common(User $user, ?string $running = null): array
    {
        return [
            'user' => $user,
            'firstName' => self::firstName($user),
            'company' => $running ?? self::company($user),
            'role' => $running !== null ? 'running' : 'for',
            'infodot' => self::infodot(),
        ];
    }
}
