<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * The fallback route: every address no route answers lands here and gets a 404.
 *
 * Why a route at all: with no matching route Laravel never runs the web middleware, so the 404 page could not read the session and showed a
 * signed-in person a "Sign in" button (R-43 polish #17, D-187). A fallback inside the web group starts the session first, so errors/404.blade.php
 * can draw the real header (account, cart, bell) for the person who is actually looking at it. It is a controller, not a closure, so route:cache works.
 *
 * A fallback also hides "wrong method": a GET to a POST-only address would match the fallback and read as a 404. So before giving a 404 it asks
 * whether another method would have been answered at this address, and if so says 405 with the allowed methods, as the router would have.
 */
final class NotFoundController extends Controller
{
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    public function __invoke(Request $request): never
    {
        $allowed = [];

        foreach (self::METHODS as $method) {
            if ($method === $request->getMethod()) {
                continue;
            }

            $probe = $request->duplicate();
            $probe->server->set('REQUEST_METHOD', $method);

            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->isFallback) {
                    continue;
                }

                if (in_array($method, $route->methods(), true) && $route->matches($probe, false)) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed);
        }

        abort(404);
    }
}
