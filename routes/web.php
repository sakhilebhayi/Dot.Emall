<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Discovery\AssistantPageController;
use App\Http\Controllers\Discovery\CategoryController;
use App\Http\Controllers\Discovery\CollectionController;
use App\Http\Controllers\Discovery\HomeController;
use App\Http\Controllers\Discovery\RecentlyViewedController;
use App\Http\Controllers\Discovery\RegionController;
use App\Http\Controllers\Discovery\SavedSearchController;
use App\Http\Controllers\Discovery\SearchController as DiscoverySearchController;
use App\Http\Controllers\GuestOrderAccessController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OperatorPaymentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemCancellationController;
use App\Http\Controllers\OrderPaymentController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ProductModerationController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ReviewPhotoController;
use App\Http\Controllers\SearchSuggestController;
use App\Http\Controllers\SellerOrderController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\StoreController;
use App\Models\User;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

// Health check for the deploy smoke test and uptime monitors (see HealthController).
Route::get('/up', HealthController::class)->name('health');

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])->middleware('throttle:10,1')->name('ecosystem.auth');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

// Content-Security-Policy violation reports (the policy is report-only). The browser posts these by itself, so there is
// no session, no cookie, no CSRF token and no user; the controller keeps only the directive and the blocked host.
Route::post('/csp-report', CspReportController::class)
    ->middleware('throttle:60,1,csp-report')
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->name('csp.report');

// Paystack's server-to-server notifications. No session, no CSRF: the HMAC
// signature header is the authentication (see PaystackWebhookController).
Route::post('/webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.paystack');

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

// The storefront is public: anyone can browse and open a product. Everything
// that spends, stores or discloses something about a person is behind auth.
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

// --- data-b ---
// JSON endpoints for discovery. Their rate limiters (suggest, assistant) are defined in DiscoveryServiceProvider, never here.
Route::get('/search/suggest', SearchSuggestController::class)->middleware('throttle:suggest')->name('search.suggest');
Route::post('/assistant/ask', AssistantController::class)->middleware('throttle:assistant')->name('assistant.ask');
// Review photos live on the private disk and are streamed only to people allowed to see the review.
Route::get('/review-photos/{photo}', ReviewPhotoController::class)->name('review-photos.show');
// --- /data-b ---

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::post('/products/{product}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
    Route::patch('/cart/{cartItem}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/{cartItem}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::post('/cart/checkout', [CartController::class, 'checkout'])->middleware('throttle:checkout')->name('cart.checkout');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/pay', [OrderPaymentController::class, 'pay'])->middleware('throttle:checkout')->name('orders.pay');
    Route::get('/orders/{order}/payment/callback', [OrderPaymentController::class, 'callback'])->name('orders.payment.callback');
    Route::post('/order-items/{orderItem}/cancel', OrderItemCancellationController::class)->name('order-items.cancel');

    Route::get('/sell', [StoreController::class, 'create'])->name('seller.store.create');
    Route::post('/sell', [StoreController::class, 'store'])->name('seller.store.store');
    Route::get('/sell/store/edit', [StoreController::class, 'edit'])->name('seller.store.edit');
    Route::patch('/sell/store', [StoreController::class, 'update'])->name('seller.store.update');

    // "create" must stay registered before "{product}"-shaped routes below,
    // otherwise it's swallowed by {product}'s implicit route-model binding.
    Route::get('/sell/products', [SellerProductController::class, 'index'])->name('seller.products.index');
    Route::get('/sell/products/create', [SellerProductController::class, 'create'])->name('seller.products.create');
    Route::post('/sell/products', [SellerProductController::class, 'store'])->name('seller.products.store');
    Route::get('/sell/products/{product}/edit', [SellerProductController::class, 'edit'])->name('seller.products.edit');
    Route::patch('/sell/products/{product}', [SellerProductController::class, 'update'])->name('seller.products.update');
    Route::delete('/sell/products/{product}', [SellerProductController::class, 'destroy'])->name('seller.products.destroy');
    Route::post('/sell/products/{product}/images', [ProductImageController::class, 'store'])->name('seller.products.images.store');
    Route::delete('/sell/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])->name('seller.products.images.destroy')->scopeBindings();
    Route::patch('/sell/products/{product}/images/{image}/cover', [ProductImageController::class, 'cover'])->name('seller.products.images.cover')->scopeBindings();
    Route::patch('/sell/products/{product}/toggle-active', [SellerProductController::class, 'toggleActive'])->name('seller.products.toggle-active');

    Route::get('/sell/orders', [SellerOrderController::class, 'index'])->name('seller.orders.index');
    Route::patch('/sell/order-items/{orderItem}/status', [SellerOrderController::class, 'updateItemStatus'])->name('seller.order-items.update-status');

    Route::get('/sell/promo-codes', [PromoCodeController::class, 'index'])->name('seller.promo-codes.index');
    Route::post('/sell/promo-codes', [PromoCodeController::class, 'store'])->name('seller.promo-codes.store');
    Route::delete('/sell/promo-codes/{promoCode}', [PromoCodeController::class, 'destroy'])->name('seller.promo-codes.destroy');

    Route::middleware('operator')->prefix('operator')->name('operator.')->group(function () {
        Route::get('/products', [ProductModerationController::class, 'index'])->name('products.index');
        Route::post('/products/{product}/approve', [ProductModerationController::class, 'approve'])->name('products.approve');
        Route::post('/products/{product}/reject', [ProductModerationController::class, 'reject'])->name('products.reject');

        Route::get('/payments', [OperatorPaymentController::class, 'index'])->name('payments.index');
        Route::post('/payments/{payment}/review', [OperatorPaymentController::class, 'review'])->name('payments.review');
        Route::post('/refunds/{refund}/retry', [OperatorPaymentController::class, 'retryRefund'])->name('refunds.retry');
        Route::post('/refunds/{refund}/mark-done', [OperatorPaymentController::class, 'markRefundDone'])->name('refunds.mark-done');
    });
});

// --- data-a ---
// The magic link in a guest's order e-mail (D-9). Public by design: the signed token is the credential. Throttled by the
// 'guest-order' limiter, which App\Providers\EmallServiceProvider defines (never in a routes file).
Route::get('/guest/orders/{order}/{token}', GuestOrderAccessController::class)
    ->middleware('throttle:guest-order')
    ->where('token', '[A-Za-z0-9_\-]{20,128}')
    ->name('guest.orders.access');
Route::get('/guest/orders/{order}/{token}/payment', [GuestOrderAccessController::class, 'paymentReturn'])
    ->middleware('throttle:guest-order')
    ->where('token', '[A-Za-z0-9_\\-]{20,128}')
    ->name('guest.orders.payment.callback');
// --- end data-a ---

// --- integration ---
// Pure aliases of pages that already exist, so the shell's navigation resolves (DESIGN.md, "Route names feature work must
// register"). A feature agent that builds the real page for one of these names deletes its line here and registers the real
// route. Nothing is registered here that is not an existing controller action: no aliases, limiters or bindings (those live in providers).

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/account', DashboardController::class)->name('account.home');
    Route::get('/sell/dashboard', DashboardController::class)->name('seller.dashboard');
    Route::get('/sell/start', [StoreController::class, 'create'])->name('sell.start');
});
// --- end integration ---

// --- w3 ---
// Discovery pages (R-43 feature agent W3): the home page, search (and /products, which is the same controller), categories, collections, the shopping
// assistant, saved searches, recently viewed and the shopper's province. Rate limiters live in DiscoveryServiceProvider, never here.
Route::get('/', HomeController::class)->name('home');
Route::get('/search', [DiscoverySearchController::class, 'index'])->name('search');
Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{category}', [DiscoverySearchController::class, 'category'])->where('category', '[a-z0-9\-]+')->name('categories.show');
Route::get('/collections', [CollectionController::class, 'index'])->name('collections.index');
Route::get('/collections/{slug}', [CollectionController::class, 'show'])->where('slug', '[a-z0-9\-]+')->name('collections.show');
Route::get('/assistant', AssistantPageController::class)->name('assistant');
Route::get('/recently-viewed', [RecentlyViewedController::class, 'index'])->name('recently-viewed.index');
Route::delete('/recently-viewed', [RecentlyViewedController::class, 'clear'])->name('recently-viewed.clear');
Route::post('/region', [RegionController::class, 'update'])->name('region.update');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/account/saved-searches', [SavedSearchController::class, 'index'])->name('account.saved-searches');
    Route::post('/account/saved-searches', [SavedSearchController::class, 'store'])->name('account.saved-searches.store');
    Route::patch('/account/saved-searches/{savedSearch}', [SavedSearchController::class, 'update'])->name('account.saved-searches.update');
    Route::delete('/account/saved-searches/{savedSearch}', [SavedSearchController::class, 'destroy'])->name('account.saved-searches.destroy');
});
// --- end w3 ---

// --- dev ---
// Local-only sign-in for design and audit sessions (docs/R43_DEMO.md). Registered only in the
// 'local' and 'testing' environments, so it does not exist in production: the route is absent, not guarded.
if (app()->environment('local', 'testing')) {
    Route::get('/dev/login/{user}', function (Request $request, string $user) {
        $account = User::query()
            ->when(ctype_digit($user), fn ($q) => $q->where('id', (int) $user), fn ($q) => $q->where('email', $user))
            ->firstOrFail();

        // Switching person: drop the previous person's session data first, or Jetstream's session guard sees a different password hash and signs the new person out again.
        $request->session()->flush();
        auth()->login($account);
        $request->session()->regenerate();

        $to = (string) $request->query('to', '/dashboard');

        return redirect(str_starts_with($to, '/') && ! str_starts_with($to, '//') ? $to : '/dashboard');
    })->name('dev.login');

    Route::get('/dev/logout', function (Request $request) {
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    })->name('dev.logout');

    // The design-system gallery: every component, with invented specimens that are never saved (resources/views/dev/kit.blade.php).
    Route::view('/dev/kit', 'dev.kit')->name('dev.kit');
}
// --- end dev ---

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
