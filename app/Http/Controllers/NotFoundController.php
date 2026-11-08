<?php

namespace App\Http\Controllers;

/**
 * The fallback route: every address no route answers lands here and gets a 404.
 *
 * Why a route at all: with no matching route Laravel never runs the web middleware, so the 404 page could not read the session and showed a
 * signed-in person a "Sign in" button (R-43 polish #17, D-187). A fallback inside the web group starts the session first, so errors/404.blade.php
 * can draw the real header (account, cart, bell) for the person who is actually looking at it. It is a controller, not a closure, so route:cache works.
 */
final class NotFoundController extends Controller
{
    public function __invoke(): never
    {
        abort(404);
    }
}
