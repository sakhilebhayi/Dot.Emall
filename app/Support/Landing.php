<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\Orders\OrderPage;
use App\Services\Seller\SellerDesk;

/**
 * What the page a person arrives on after signing in (/dashboard) shows. Everything here is read from that person's own records
 * or from the live catalogue: nothing is estimated, and where there is nothing the view says so. Each role gets ONE primary action,
 * chosen from the real state of the shop:
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

    /** @return array<string, mixed> */
    public static function buyer(User $user, OrderPage $page): array
    {
        $shelf = Product::query()->browsable()->with(['images', 'store:id,name'])->latest('id')->limit(3)->get();
        $orders = Order::query()->where('user_id', $user->id)->with(['items', 'groups'])->latest('id')->limit(3)->get();
        $open = $shelf->isNotEmpty();

        return self::common($user) + [
            'shelf' => $shelf,
            'orders' => $orders,
            'statuses' => $orders->mapWithKeys(fn (Order $o) => [$o->id => $page->status($o, $o->items)['key']]),
            'awaiting' => Order::query()->where('user_id', $user->id)->where('requires_payment', true)
                ->whereIn('payment_status', ['unpaid', 'pending', 'failed'])->where('status', '!=', 'cancelled')->count(),
            'isOperator' => (bool) $user->is_platform_operator,
            'primary' => $open
                ? ['label' => 'Shop the mall', 'href' => route('products.index'), 'route' => 'products.index']
                : ['label' => 'Open a shop', 'href' => route('sell.start'), 'route' => 'sell.start'],
        ];
    }

    /** @return array<string, mixed> */
    public static function seller(User $user, Store $store, SellerDesk $desk): array
    {
        $listings = Product::query()->where('store_id', $store->id)->with('images')->latest('id')->limit(3)->get();
        $hasProducts = $listings->isNotEmpty();

        return self::common($user) + [
            'store' => $store,
            'listings' => $listings,
            'needs' => $desk->needsYou($store, $user),
            'primary' => $hasProducts
                ? ['label' => 'Manage your products', 'href' => route('seller.products.index'), 'route' => 'seller.products.index']
                : ['label' => 'Add your first product', 'href' => route('seller.products.create'), 'route' => 'seller.products.create'],
        ];
    }

    /** @return array<string, mixed> */
    private static function common(User $user): array
    {
        return [
            'user' => $user,
            'firstName' => self::firstName($user),
            'company' => self::company($user),
            'infodot' => self::infodot(),
        ];
    }
}
