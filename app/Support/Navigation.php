<?php

namespace App\Support;

use App\Services\Catalogue\CategoryTree;
use Throwable;

/**
 * The navigation of the four layout contexts, as data. Each item names the route it wants (`route`), the routes it falls back to while the
 * feature route does not exist yet (`alt`), the icon, and which route names mark it as the current page (`match`). The layouts render these
 * through Shell::first(), so an unregistered route gives href="#" and data-pending-route, never an exception. DESIGN.md lists the names.
 */
final class Navigation
{
    /** @return list<array{label: string, route: string, alt?: list<string>, icon: string, match: list<string>}> */
    public static function account(): array
    {
        return [
            ['label' => 'Overview', 'route' => 'account.home', 'alt' => ['dashboard'], 'icon' => 'home', 'match' => ['account.home', 'dashboard']],
            ['label' => 'Orders', 'route' => 'orders.index', 'icon' => 'receipt', 'match' => ['orders.*', 'order-items.*']],
            ['label' => 'Returns', 'route' => 'account.returns', 'icon' => 'return', 'match' => ['account.returns*']],
            ['label' => 'Wishlist', 'route' => 'wishlist.index', 'icon' => 'heart', 'match' => ['wishlist.*']],
            ['label' => 'Saved searches', 'route' => 'account.saved-searches', 'icon' => 'bookmark', 'match' => ['account.saved-searches*']],
            ['label' => 'Addresses', 'route' => 'account.addresses', 'icon' => 'pin', 'match' => ['account.addresses*']],
            ['label' => 'Alerts', 'route' => 'account.alerts', 'icon' => 'bell', 'match' => ['account.alerts*']],
            ['label' => 'Profile and security', 'route' => 'profile.show', 'icon' => 'user', 'match' => ['profile.*', 'teams.*', 'api-tokens.*']],
        ];
    }

    /** @return list<array{label: string, route: string, alt?: list<string>, icon: string, match: list<string>}> */
    public static function seller(): array
    {
        return [
            ['label' => 'Dashboard', 'route' => 'seller.dashboard', 'alt' => ['dashboard'], 'icon' => 'home', 'match' => ['seller.dashboard', 'dashboard']],
            ['label' => 'Products', 'route' => 'seller.products.index', 'icon' => 'box', 'match' => ['seller.products.*']],
            ['label' => 'Orders', 'route' => 'seller.orders.index', 'icon' => 'package-check', 'match' => ['seller.orders.*', 'seller.order-items.*', 'seller.shipments.*']],
            ['label' => 'Returns', 'route' => 'seller.returns', 'icon' => 'return', 'match' => ['seller.returns*']],
            ['label' => 'Shipping', 'route' => 'seller.shipping', 'icon' => 'truck', 'match' => ['seller.shipping*']],
            ['label' => 'Promotions', 'route' => 'seller.promo-codes.index', 'icon' => 'percent', 'match' => ['seller.promo-codes.*']],
            ['label' => 'Analytics', 'route' => 'seller.analytics', 'icon' => 'chart', 'match' => ['seller.analytics*']],
            ['label' => 'Ledger', 'route' => 'seller.ledger', 'icon' => 'receipt', 'match' => ['seller.ledger*']],
            ['label' => 'Reviews and questions', 'route' => 'seller.feedback', 'icon' => 'message', 'match' => ['seller.feedback', 'seller.questions.*', 'seller.reviews.*']],
            ['label' => 'Store profile', 'route' => 'seller.store.edit', 'icon' => 'store', 'match' => ['seller.store.*']],
        ];
    }

    /** @return list<array{label: string, short?: string, route: string, alt?: list<string>, icon: string, match: list<string>}> */
    public static function operator(): array
    {
        return [
            ['label' => 'Needs a person', 'short' => 'Queue', 'route' => 'operator.home', 'icon' => 'home', 'match' => ['operator.home']],
            ['label' => 'Listings', 'route' => 'operator.products.index', 'icon' => 'box', 'match' => ['operator.products.*', 'operator.flags.*']],
            ['label' => 'Stores', 'route' => 'operator.stores.index', 'icon' => 'store', 'match' => ['operator.stores.*']],
            ['label' => 'Reports', 'route' => 'operator.reports', 'icon' => 'flag', 'match' => ['operator.reports*']],
            ['label' => 'Reviews and Q&A', 'route' => 'operator.moderation.index', 'icon' => 'message', 'match' => ['operator.moderation.*']],
            ['label' => 'Returns', 'route' => 'operator.returns.index', 'icon' => 'return', 'match' => ['operator.returns.*']],
            ['label' => 'Payments', 'route' => 'operator.payments.index', 'icon' => 'card', 'match' => ['operator.payments.*', 'operator.refunds.*']],
            ['label' => 'Ledger', 'route' => 'operator.ledger.index', 'icon' => 'receipt', 'match' => ['operator.ledger.*']],
            ['label' => 'Metrics', 'route' => 'operator.metrics', 'icon' => 'chart', 'match' => ['operator.metrics*']],
            ['label' => 'Merchandising', 'route' => 'operator.merch.index', 'icon' => 'tag', 'match' => ['operator.merch.*', 'operator.collections.*', 'operator.search.*', 'operator.catalogue.*']],
            ['label' => 'People', 'route' => 'operator.people.index', 'icon' => 'user', 'match' => ['operator.people.*', 'operator.team*']],
            ['label' => 'Platform health', 'route' => 'operator.health', 'icon' => 'shield-check', 'match' => ['operator.health']],
            ['label' => 'Audit trail', 'route' => 'operator.audit', 'icon' => 'list', 'match' => ['operator.audit']],
        ];
    }

    /**
     * Footer columns. Legal pages are the real Jetstream and cookie routes; help pages are the feature agents' routes (pending until registered).
     *
     * @return list<array{title: string, links: list<array{label: string, route: string, alt?: list<string>}>}>
     */
    public static function footer(): array
    {
        return [
            ['title' => 'Shop', 'links' => [
                ['label' => 'All products', 'route' => 'products.index'],
                ['label' => 'Categories', 'route' => 'categories.index'],
                ['label' => 'Stores', 'route' => 'stores.index'],
                ['label' => 'Wishlist', 'route' => 'wishlist.index'],
            ]],
            ['title' => 'Help', 'links' => [
                ['label' => 'Help centre', 'route' => 'help.index'],
                ['label' => 'Delivery', 'route' => 'help.delivery'],
                ['label' => 'Returns and refunds', 'route' => 'help.returns'],
                ['label' => 'Contact us', 'route' => 'help.contact'],
            ]],
            ['title' => 'Sell on Emall', 'links' => [
                ['label' => 'Sell on Emall', 'route' => 'sell.start', 'alt' => ['seller.store.create']],
                ['label' => 'Seller help', 'route' => 'help.sellers'],
            ]],
            ['title' => 'Legal', 'links' => [
                ['label' => 'Privacy policy', 'route' => 'policy.show'],
                ['label' => 'Terms of service', 'route' => 'terms.show'],
                ['label' => 'Cookie policy', 'route' => 'cookies'],
            ]],
        ];
    }

    /**
     * Top-level categories with their children for the mega menu and the phone drawer: the shared category tree, active
     * categories only, and only those a shopper can buy from (a category with no listed product leads to an empty page, so it
     * is not offered). Returns [] when there is none (or the database is unreachable). Feature work may pass its own `categories`
     * prop to <x-store-layout> instead.
     *
     * @return list<array{name: string, href: string, children: list<array{name: string, href: string}>}>
     */
    public static function categories(): array
    {
        try {
            $link = static function (array $category): string {
                $named = Shell::first(['categories.show'], ['category' => $category['slug'] ?? $category['id']]);

                return $named['url'] !== '#' ? $named['url'] : (Shell::routeExists('products.index') ? route('products.index', ['category' => $category['id']]) : '#');
            };

            return array_values(array_map(
                fn (array $root) => [
                    'name' => $root['name'],
                    'href' => $link($root),
                    'children' => array_values(array_map(
                        fn (array $child) => ['name' => $child['name'], 'href' => $link($child)],
                        array_filter($root['children'], fn (array $child) => $child['rollup_count'] > 0),
                    )),
                ],
                array_filter(app(CategoryTree::class)->tree(), fn (array $root) => $root['rollup_count'] > 0),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /** The signed-in user's relationship to the platform, for the account menu. Safe when the database is down. */
    public static function viewer(): array
    {
        $user = null;
        try {
            $user = request()->user();
        } catch (Throwable) {
        }

        $hasStore = false;
        $isOperator = false;
        try {
            $hasStore = $user && $user->store !== null;
            $isOperator = $user && (bool) ($user->is_platform_operator ?? false);
        } catch (Throwable) {
        }

        return ['user' => $user, 'hasStore' => (bool) $hasStore, 'isOperator' => (bool) $isOperator];
    }
}
