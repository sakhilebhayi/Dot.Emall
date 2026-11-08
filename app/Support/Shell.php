<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Small, failure-proof helpers the layouts lean on. Everything here must keep working when the database or session is down
 * (the error pages render through the same layouts), so each lookup is wrapped and falls back to a harmless value.
 */
final class Shell
{
    /**
     * Browser chrome colours for <meta name="theme-color">. A meta tag cannot read CSS variables, so they live here; tests/Unit/DesignTokensTest.php
     * checks they equal --ground in resources/css/tokens.css.
     */
    public const THEME_COLOR_LIGHT = '#f6f7f4';

    public const THEME_COLOR_DARK = '#161830';

    /**
     * URL for a named route, or '#' while that route is not registered yet. The navigation links through this so the shell renders
     * before the feature pages exist; DESIGN.md lists the names feature work must register. Use emall_pending() beside it to mark the
     * link, so a pending link is visible in the DOM (data-pending-route) and in tests.
     *
     * @param  array<string, mixed>|string|int  $parameters
     */
    public static function route(string $name, mixed $parameters = []): string
    {
        return Route::has($name) ? route($name, $parameters) : '#';
    }

    /**
     * The first registered route among several names (a feature's own route, then the one the first version of the shop already had), as
     * ['url' => ..., 'name' => the name used or the first name when none exists]. Nothing registered gives '#'.
     *
     * @param  list<string>  $names
     * @return array{url: string, name: string}
     */
    public static function first(array $names, mixed $parameters = []): array
    {
        foreach ($names as $name) {
            if (Route::has($name)) {
                return ['url' => route($name, $parameters), 'name' => $name];
            }
        }

        return ['url' => '#', 'name' => $names[0] ?? ''];
    }

    public static function routeExists(string $name): bool
    {
        return Route::has($name);
    }

    /** The current route name matches one of these (dot patterns allowed: 'orders.*'). */
    public static function active(string|array $patterns): bool
    {
        $current = Route::currentRouteName();

        return $current !== null && request()->routeIs(...(array) $patterns);
    }

    /**
     * Items in the cart. Guests have carts too, so the number is not tied to a user: the cart feature binds the real counter once with
     * app()->bind('emall.cart_count', fn () => $count), and until then this reads the signed-in user's cart rows, or returns 0.
     */
    public static function cartCount(): int
    {
        try {
            if (app()->bound('emall.cart_count')) {
                return max(0, (int) app('emall.cart_count')());
            }

            $user = request()->user();
            if ($user && class_exists(\App\Models\CartItem::class)) {
                return (int) \App\Models\CartItem::query()->where('user_id', $user->id)->sum('quantity');
            }
        } catch (Throwable) {
            // The shell must render with the database down.
        }

        return 0;
    }

    /** Unread notifications for the signed-in user, 0 for guests or when the table is unreachable. */
    public static function unreadNotifications(): int
    {
        try {
            return (int) (request()->user()?->unreadNotifications()->count() ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * What the test-mode badge says, or null in production. Paystack in test mode is the honest signal on this platform: a key that
     * starts sk_test or pk_test, or APP_ENV other than production, means no real money moves.
     */
    public static function testModeLabel(): ?string
    {
        try {
            $key = (string) config('services.paystack.secret_key', config('paystack.secret_key', ''));
            if (str_starts_with($key, 'sk_test')) {
                return 'Test mode: no real money moves';
            }
            if (! app()->environment('production')) {
                return 'Test mode: '.app()->environment().' environment';
            }
        } catch (Throwable) {
        }

        return null;
    }

    /** First letters of a name, for the avatar. */
    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $letters = '';
        foreach (array_slice(array_filter($parts), 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : '?';
    }

    /** The per-request CSP nonce set by SecurityHeaders, or '' when none (console, tests that skip the middleware). */
    public static function nonce(): string
    {
        try {
            return (string) (request()->attributes->get('csp_nonce') ?? '');
        } catch (Throwable) {
            return '';
        }
    }
}
