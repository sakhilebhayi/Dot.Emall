<?php

use App\Support\Shell;

if (! function_exists('emall_route')) {
    /**
     * URL for a named route, or '#' while the route is not registered. See App\Support\Shell::route() and DESIGN.md (route names).
     *
     * @param  array<string, mixed>|string|int  $parameters
     */
    function emall_route(string $name, mixed $parameters = []): string
    {
        return Shell::route($name, $parameters);
    }
}

if (! function_exists('emall_pending')) {
    /** Attribute text for a link whose route is not registered yet: data-pending-route="name", empty otherwise. */
    function emall_pending(string $name): string
    {
        return Shell::routeExists($name) ? '' : 'data-pending-route="'.e($name).'"';
    }
}

if (! function_exists('csp_nonce')) {
    /** The per-request CSP nonce set by App\Http\Middleware\SecurityHeaders, or '' when there is none. */
    function csp_nonce(): string
    {
        return Shell::nonce();
    }
}

if (! function_exists('emall_pending_name')) {
    /** The route name while it is not registered, null once it is: bind it on a component tag as :data-pending-route="emall_pending_name('name')" (null attributes are left out). */
    function emall_pending_name(string $name): ?string
    {
        return Shell::routeExists($name) ? null : $name;
    }
}
