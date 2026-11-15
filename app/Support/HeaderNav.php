<?php

namespace App\Support;

/**
 * What the shared dot-ui header (<x-dot.header>) is given, as data (R-52). One place, so the storefront, the account and the desks agree and a test can read it.
 *
 * Links go through Shell::first(), like the rest of the navigation: a route that is not registered yet gives href="#" with data-pending-route, never an exception.
 * The header's bar shows at most four daily items; everything else lives in a named dropdown or in the phone drawer.
 */
final class HeaderNav
{
    /** @param  list<string>  $names */
    private static function link(string $label, array $names, bool $current = false): array
    {
        $r = Shell::first($names);

        return ['label' => $label, 'href' => $r['url'], 'current' => $current, 'attrs' => $r['url'] === '#' ? ['data-pending-route' => $r['name']] : []];
    }

    /**
     * The storefront's primary items: a "Categories" dropdown (every top-level category), Stores, Sell ("Sell on Emall" in the drawer and the account menu: a longer label pushed Help into More at 1440px) and Help.
     *
     * @param  list<array{name: string, href: string, children: list<array{name: string, href: string}>}>  $categories
     * @return list<array<string, mixed>>
     */
    public static function store(array $categories): array
    {
        $routeIs = fn (string ...$p) => request()->routeIs(...$p);
        $nav = [];

        if ($categories !== []) {
            $items = [self::link('All products', ['products.index'], $routeIs('products.index') && ! request()->filled('category'))];
            foreach ($categories as $c) {
                $items[] = ['label' => $c['name'], 'href' => $c['href']];
            }
            $nav[] = ['label' => 'Categories', 'items' => $items];
        } else {
            $nav[] = self::link('All products', ['products.index'], $routeIs('products.*'));
        }

        $nav[] = self::link('Stores', ['stores.index'], $routeIs('stores.*'));
        $nav[] = self::link('Sell', ['sell.start', 'seller.store.create'], $routeIs('sell.start', 'seller.store.create'));
        $nav[] = self::link('Help', ['help.index'], $routeIs('help.*'));

        return $nav;
    }

    /**
     * The account menu: links, theme and sign out. `$desk` swaps the shop links for the way back to the shop (the desks have their own rail).
     *
     * @param  array{user: ?object, hasStore: bool, isOperator: bool}  $viewer
     * @return array<string, mixed>|null
     */
    public static function account(array $viewer, bool $desk = false): ?array
    {
        $user = $viewer['user'];

        if (! $user) {
            return null;
        }

        $links = [];
        if ($desk) {
            $links[] = ['label' => 'Back to the shop', 'href' => url('/')];
        } else {
            $links[] = self::link('Account overview', ['account.home', 'dashboard'], request()->routeIs('account.home'));
            $links[] = self::link('Orders', ['orders.index'], request()->routeIs('orders.*'));
            $links[] = self::link('Wishlist', ['wishlist.index'], request()->routeIs('wishlist.*'));
            $links[] = $viewer['hasStore']
                ? self::link('Seller desk', ['seller.dashboard', 'seller.products.index'])
                : self::link('Sell on Emall', ['seller.store.create']);
            if ($viewer['isOperator']) {
                $links[] = self::link('Operator area', ['operator.products.index']);
            }
        }
        $links[] = self::link('Profile and security', ['profile.show'], request()->routeIs('profile.*'));

        return [
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'links' => $links,
            'signOut' => ['action' => route('logout')],
            'theme' => true,
        ];
    }

    /** The signed-out pair. Sign-up is closed (accounts come from InfoDot), so only "Sign in" is offered. */
    public static function guest(): array
    {
        return ['login' => ['label' => 'Sign in', 'href' => route('login')]];
    }
}
