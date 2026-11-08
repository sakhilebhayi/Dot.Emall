<?php

/*
 * Opens every page as the right signed-in person, in-process, against the application exactly as production boots it:
 * routes, config and events must ALL be cached or this refuses to run. Drive it with tests/Support/signed-in-smoke.sh,
 * which builds a throwaway SQLite database, caches, runs this, and cleans up.
 *
 *   php tests/Support/signed-in-smoke.php
 *
 * Exit code 0 when every page answers what it should and no authenticated route was left unlisted.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\SignedInSmoke;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

foreach (['routes' => $app->routesAreCached(), 'config' => $app->configurationIsCached(), 'events' => $app->eventsAreCached()] as $what => $cached) {
    if (! $cached) {
        fwrite(STDERR, "REFUSING TO RUN: {$what} are not cached. Use tests/Support/signed-in-smoke.sh.\n");
        exit(2);
    }
}

Artisan::call('migrate', ['--force' => true]);
$host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';

$smoke = new SignedInSmoke;
$people = $smoke->seed();
$kernel = $app->make(HttpKernel::class);

$failures = 0;
printf("%-9s %-46s %s\n", 'AS', 'GET', 'STATUS');

foreach ($smoke->pages() as $page) {
    // A new browser per page: no session left over from the previous person (the array driver lives in this process).
    $app['session.store']->flush();
    Auth::forgetGuards();
    Auth::shouldUse('web');

    if ($page['who'] !== 'guest') {
        Auth::guard('web')->setUser($people[$page['who']]);
    }

    $request = Request::create('https://'.$host.$page['uri'], 'GET');
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);

    $expected = $page['expect'] ?? 200;
    $ok = $status === $expected;
    $failures += $ok ? 0 : 1;
    printf("%-9s %-46s %d%s\n", $page['who'], $page['uri'], $status, $ok ? '' : "   <-- FAIL (expected {$expected})");
}

$smoke->forget();

// What only the cached boot can lose: the `operator` alias and the named limiters. The operator pages above prove the
// alias (a missing one is a 500 for the operator, a 403 for everyone else); these prove the limiters.
$app['session.store']->flush();
Auth::forgetGuards();
foreach (['checkout', 'webhooks', 'suggest', 'assistant', 'community', 'guest-order', 'guest-cart'] as $limiter) {
    $ok = RateLimiter::limiter($limiter) !== null;
    $failures += $ok ? 0 : 1;
    printf("%-9s %-46s %s\n", 'limiter', $limiter, $ok ? 'registered' : 'MISSING   <-- FAIL');
}

// The guest order link (D-9): a wrong token is the same 404 whatever the order id, and its throttle (defined in a provider,
// never in the routes file) survives route:cache. A 500 here would mean the limiter or the route was lost.
$request = Request::create('https://'.$host.'/guest/orders/1/'.str_repeat('a', 43), 'GET');
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$ok = $response->getStatusCode() === 404;
$failures += $ok ? 0 : 1;
printf("%-9s %-46s %d%s\n", 'anyone', 'GET /guest/orders/1/<wrong token>', $response->getStatusCode(), $ok ? '  (expected 404)' : '   <-- FAIL (expected 404)');

// A webhook without a signature is refused by the application itself (the route and its limiter are cached too).
$request = Request::create('https://'.$host.'/webhooks/paystack', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"event":"charge.success"}');
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$ok = $response->getStatusCode() === 401;
$failures += $ok ? 0 : 1;
printf("%-9s %-46s %d%s\n", 'anyone', 'POST /webhooks/paystack (unsigned)', $response->getStatusCode(), $ok ? '  (expected 401)' : '   <-- FAIL (expected 401)');

// Host pinning (TrustHosts, F-09): an unknown Host is refused, a health probe is not.
foreach ([['https://evil.smoke.test/login', 400], ['https://evil.smoke.test/up', 200]] as [$url, $expected]) {
    $app['session.store']->flush();
    Auth::forgetGuards();
    $request = Request::create($url, 'GET');
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $kernel->terminate($request, $response);
    $ok = $status === $expected;
    $failures += $ok ? 0 : 1;
    printf("%-9s %-46s %d%s\n", 'anyone', $url, $status, $ok ? "  (expected {$expected})" : "   <-- FAIL (expected {$expected})");
}

// A real sign-in, not a user planted in the guard: InfoDot's launch page posts a handoff token, the app starts a session
// and sets a cookie, and the NEXT requests (with only that cookie) must be authenticated. This is the path that broke on
// Dot.Press and Dot.Pulse (sign in through SSO, then every call answers "Unauthenticated"): a stateful-domain or cookie
// setting that only shows once config is cached and APP_URL is the production host.
$token = $people['buyer']->createToken('smoke-handoff', ['ecosystem:read'])->plainTextToken;
$app['session.store']->flush();
Auth::forgetGuards();
Auth::shouldUse('web'); // each real request is a new process, with the default guard; an earlier page in this one switched it to sanctum
$post = Request::create('https://'.$host.'/auth/ecosystem', 'POST', ['token' => $token, 'redirect' => '/cart'], [], [], ['HTTP_ORIGIN' => 'https://infodot.co.za']);
$response = $kernel->handle($post);
$kernel->terminate($post, $response);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
$ok = $response->getStatusCode() === 302 && str_ends_with((string) $response->headers->get('Location'), '/cart') && $cookies !== [];
$failures += $ok ? 0 : 1;
printf("%-9s %-46s %d%s\n", 'sso', 'POST /auth/ecosystem (handoff token)', $response->getStatusCode(), $ok ? '  -> /cart, cookie set' : '   <-- FAIL (expected 302 to /cart with a session cookie)');

foreach (['/cart', '/orders', '/account'] as $uri) {
    Auth::forgetGuards();
    Auth::shouldUse('web');
    $next = Request::create('https://'.$host.$uri, 'GET', [], $cookies);
    $response = $kernel->handle($next);
    $kernel->terminate($next, $response);
    $ok = $response->getStatusCode() === 200;
    $failures += $ok ? 0 : 1;
    printf("%-9s %-46s %d%s\n", 'sso', 'GET '.$uri.' (cookie only)', $response->getStatusCode(), $ok ? '' : '   <-- FAIL (expected 200: the session did not survive)');
}

$uncovered = $smoke->uncoveredRoutes();
foreach ($uncovered as $uri) {
    fwrite(STDERR, "UNLISTED authenticated route: {$uri} (open it in SignedInSmoke::pages() or explain it in SKIPPED)\n");
}

$total = count($smoke->pages());
printf("\n%d pages, %d failed, %d unlisted authenticated routes (routes, config and events cached)\n", $total, $failures, count($uncovered));

exit($failures === 0 && $uncovered === [] ? 0 : 1);
