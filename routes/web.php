<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DevFakePaystackController;
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
use App\Http\Controllers\GuestOrderController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OrderActionsController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\ReturnPhotoController;
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

// --- w5 ---
// Cart, checkout, orders, returns, account and help (R-43 W5). Registered here and nowhere else; no limiter, alias or binding is
// defined in this file (App\Providers\CheckoutServiceProvider and EmallServiceProvider own those).
// The cart and checkout are PUBLIC: a guest keeps a session cart and checks out with an e-mail address (D-9).
Route::middleware('throttle:guest-cart')->group(function () {
    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
    Route::patch('/cart/lines/{variant}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('/cart/lines/{variant}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::post('/cart/promo', [CartController::class, 'applyPromo'])->name('cart.promo');
    Route::delete('/cart/promo', [CartController::class, 'clearPromo'])->name('cart.promo.clear');
    Route::post('/cart/region', [CartController::class, 'setRegion'])->name('cart.region');
    Route::post('/cart/delivery', [CartController::class, 'setDelivery'])->name('cart.delivery');
    Route::post('/cart/prices/accept', [CartController::class, 'acceptPrices'])->name('cart.prices.accept');

    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout/delivery', [CheckoutController::class, 'delivery'])->name('checkout.delivery');
});
Route::post('/checkout', [CheckoutController::class, 'place'])->middleware('throttle:checkout')->name('checkout.place');

// Testing only: a local stand-in for Paystack's hosted page. Present only in local/testing AND with EMALL_E2E_FAKE_PAYSTACK on.
if (app()->environment('local', 'testing') && config('emall.e2e_fake_paystack')) {
    Route::get('/dev/fake-paystack/{reference}', [DevFakePaystackController::class, 'show'])->name('dev.fake-paystack');
    Route::post('/dev/fake-paystack/{reference}', [DevFakePaystackController::class, 'complete'])->name('dev.fake-paystack.complete');
}

// Guest order pages (D-9, D-19): reached from the e-mailed link (guest.orders.access), which stores a grant for that one order in this
// session. Without the grant every one of these answers the same 404 as a wrong token. The {order} segment is the order id.
Route::middleware('throttle:guest-order')->prefix('guest/orders/{order}')->name('guest.orders.')->group(function () {
    Route::get('/', [GuestOrderController::class, 'show'])->name('show');
    Route::get('/receipt', [OrderActionsController::class, 'receipt'])->name('receipt');
    Route::post('/pay', [OrderActionsController::class, 'pay'])->name('pay');
    Route::post('/payment/check', [OrderActionsController::class, 'checkPayment'])->name('payment.check');
    Route::post('/message', [OrderActionsController::class, 'message'])->name('message');
    Route::post('/report', [OrderActionsController::class, 'report'])->name('report');
    Route::post('/reorder', [OrderActionsController::class, 'reorder'])->name('reorder');
    Route::post('/items/{item}/receive', [OrderActionsController::class, 'receive'])->name('items.receive')->scopeBindings();
    Route::post('/items/{item}/cancel', [OrderActionsController::class, 'cancel'])->name('items.cancel')->scopeBindings();
    Route::get('/items/{item}/return', [ReturnController::class, 'create'])->name('returns.create')->scopeBindings();
    Route::post('/items/{item}/return', [ReturnController::class, 'store'])->name('returns.store')->scopeBindings();
});
Route::get('/return-photos/{return}/{index}', ReturnPhotoController::class)->whereNumber('index')->name('return-photos.show');

// Signed-in buyer: the cart's wishlist move, order actions, returns, account area. All behind sign-in and the usual verified check.
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->group(function () {
    Route::post('/cart/lines/{variant}/wishlist', [CartController::class, 'moveToWishlist'])->name('cart.wishlist');

    Route::post('/orders/{order}/payment/check', [OrderActionsController::class, 'checkPayment'])->name('orders.payment.check');
    Route::get('/orders/{order}/receipt', [OrderActionsController::class, 'receipt'])->name('orders.receipt');
    Route::post('/orders/{order}/message', [OrderActionsController::class, 'message'])->name('orders.message');
    Route::post('/orders/{order}/report', [OrderActionsController::class, 'report'])->name('orders.report');
    Route::post('/orders/{order}/reorder', [OrderActionsController::class, 'reorder'])->name('orders.reorder');
    Route::post('/orders/{order}/items/{item}/receive', [OrderActionsController::class, 'receive'])->name('orders.items.receive')->scopeBindings();
    Route::get('/orders/{order}/items/{item}/return', [ReturnController::class, 'create'])->name('orders.returns.create')->scopeBindings();
    Route::post('/orders/{order}/items/{item}/return', [ReturnController::class, 'store'])->name('orders.returns.store')->scopeBindings();

    Route::get('/account', [AccountController::class, 'home'])->name('account.home');
    Route::get('/account/returns', [ReturnController::class, 'index'])->name('account.returns');
    Route::get('/account/returns/{return}', [ReturnController::class, 'show'])->name('account.returns.show');
    Route::post('/account/returns/{return}/escalate', [ReturnController::class, 'escalate'])->name('account.returns.escalate');

    Route::get('/account/addresses', [AddressController::class, 'index'])->name('account.addresses');
    Route::post('/account/addresses', [AddressController::class, 'store'])->name('account.addresses.store');
    Route::get('/account/addresses/{address}/edit', [AddressController::class, 'edit'])->name('account.addresses.edit');
    Route::patch('/account/addresses/{address}', [AddressController::class, 'update'])->name('account.addresses.update');
    Route::delete('/account/addresses/{address}', [AddressController::class, 'destroy'])->name('account.addresses.destroy');
    Route::post('/account/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('account.addresses.default');

    Route::get('/account/notifications', [NotificationPreferenceController::class, 'edit'])->name('account.notifications');
    Route::put('/account/notifications', [NotificationPreferenceController::class, 'update'])->name('account.notifications.update');
    Route::get('/account/privacy', [PrivacyController::class, 'show'])->name('account.privacy');
    Route::get('/account/privacy/export', [PrivacyController::class, 'export'])->name('account.privacy.export');
});

// Help (public).
Route::get('/help', [HelpController::class, 'index'])->name('help.index');
Route::get('/help/delivery', [HelpController::class, 'delivery'])->name('help.delivery');
Route::get('/help/returns', [HelpController::class, 'returns'])->name('help.returns');
Route::get('/help/contact', [HelpController::class, 'contact'])->name('help.contact');
Route::get('/help/selling', [HelpController::class, 'sellers'])->name('help.sellers');
// --- end w5 ---

// --- integration ---
// Pure aliases of pages that already exist, so the shell's navigation resolves (DESIGN.md, "Route names feature work must
// register"). A feature agent that builds the real page for one of these names deletes its line here and registers the real
// route. Nothing is registered here that is not an existing controller action: no aliases, limiters or bindings (those live in providers).

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
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

// --- w7 ---
// The operator desk (R-43 W7). Every route is behind sign-in, a verified e-mail and the `operator` middleware alias (registered in
// bootstrap/app.php, never here). The older operator routes (products, payments, refunds) stay in the group above.
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
    'operator',
])->prefix('operator')->name('operator.')->group(function () {
    Route::get('/', \App\Http\Controllers\Operator\HomeController::class)->name('home');

    // Listings: the detail page and the flagged-listing review aid (the queue, approve and reject are above).
    Route::get('/products/{product}', [ProductModerationController::class, 'show'])->name('products.show')->whereNumber('product');
    Route::get('/flags', [\App\Http\Controllers\Operator\FlagController::class, 'index'])->name('flags.index');
    Route::post('/flags/{product}/dismiss', [\App\Http\Controllers\Operator\FlagController::class, 'dismiss'])->name('flags.dismiss');

    // Stores.
    Route::get('/stores', [\App\Http\Controllers\Operator\StoreReviewController::class, 'index'])->name('stores.index');
    Route::get('/stores/{store}', [\App\Http\Controllers\Operator\StoreReviewController::class, 'show'])->name('stores.show');
    Route::post('/stores/{store}/approve', [\App\Http\Controllers\Operator\StoreReviewController::class, 'approve'])->name('stores.approve');
    Route::post('/stores/{store}/reject', [\App\Http\Controllers\Operator\StoreReviewController::class, 'reject'])->name('stores.reject');
    Route::post('/stores/{store}/suspend', [\App\Http\Controllers\Operator\StoreReviewController::class, 'suspend'])->name('stores.suspend');
    Route::post('/stores/{store}/reinstate', [\App\Http\Controllers\Operator\StoreReviewController::class, 'reinstate'])->name('stores.reinstate');

    // Reviews and questions held for a check.
    Route::get('/moderation', [\App\Http\Controllers\Operator\ContentModerationController::class, 'index'])->name('moderation.index');
    Route::post('/moderation/reviews/{review}/release', [\App\Http\Controllers\Operator\ContentModerationController::class, 'releaseReview'])->name('moderation.reviews.release');
    Route::post('/moderation/reviews/{review}/remove', [\App\Http\Controllers\Operator\ContentModerationController::class, 'removeReview'])->name('moderation.reviews.remove');
    Route::post('/moderation/questions/{question}/release', [\App\Http\Controllers\Operator\ContentModerationController::class, 'releaseQuestion'])->name('moderation.questions.release');
    Route::post('/moderation/questions/{question}/remove', [\App\Http\Controllers\Operator\ContentModerationController::class, 'removeQuestion'])->name('moderation.questions.remove');
    Route::post('/moderation/answers/{answer}/remove', [\App\Http\Controllers\Operator\ContentModerationController::class, 'removeAnswer'])->name('moderation.answers.remove');

    // Reports.
    Route::get('/reports', [\App\Http\Controllers\Operator\ReportController::class, 'index'])->name('reports');
    Route::get('/reports/{type}/{id}', [\App\Http\Controllers\Operator\ReportController::class, 'show'])->name('reports.show')->whereNumber('id');
    Route::post('/reports/{type}/{id}/resolve', [\App\Http\Controllers\Operator\ReportController::class, 'resolve'])->name('reports.resolve')->whereNumber('id');

    // Returns that reached the operator.
    Route::get('/returns', [\App\Http\Controllers\Operator\ReturnController::class, 'index'])->name('returns.index');
    Route::get('/returns/{returnRequest}', [\App\Http\Controllers\Operator\ReturnController::class, 'show'])->name('returns.show');
    Route::post('/returns/{returnRequest}/settle', [\App\Http\Controllers\Operator\ReturnController::class, 'settle'])->name('returns.settle');

    // Money: the payment events log (the desk and the refund actions are above) and the ledger reconciliation.
    Route::get('/payment-events', [OperatorPaymentController::class, 'events'])->name('payments.events');
    Route::get('/ledger', [\App\Http\Controllers\Operator\LedgerController::class, 'index'])->name('ledger.index');
    Route::post('/ledger/run', [\App\Http\Controllers\Operator\LedgerController::class, 'run'])->middleware('throttle:6,1')->name('ledger.run');

    // Metrics.
    Route::get('/metrics', [\App\Http\Controllers\Operator\MetricsController::class, 'index'])->name('metrics');
    Route::get('/metrics/export', [\App\Http\Controllers\Operator\MetricsController::class, 'export'])->name('metrics.export');

    // Merchandising: collections, searches, synonyms, "often needed with", categories, featured stores.
    Route::view('/merchandising', 'operator.merchandising')->name('merch.index');
    Route::get('/collections', [\App\Http\Controllers\Operator\CollectionController::class, 'index'])->name('collections.index');
    Route::get('/collections/create', [\App\Http\Controllers\Operator\CollectionController::class, 'create'])->name('collections.create');
    Route::post('/collections', [\App\Http\Controllers\Operator\CollectionController::class, 'store'])->name('collections.store');
    Route::post('/collections/preview', [\App\Http\Controllers\Operator\CollectionController::class, 'preview'])->middleware('throttle:60,1')->name('collections.preview');
    Route::get('/collections/find', [\App\Http\Controllers\Operator\CollectionController::class, 'find'])->middleware('throttle:60,1')->name('collections.find');
    Route::get('/collections/{collection}/edit', [\App\Http\Controllers\Operator\CollectionController::class, 'edit'])->name('collections.edit');
    Route::patch('/collections/{collection}', [\App\Http\Controllers\Operator\CollectionController::class, 'update'])->name('collections.update');
    Route::delete('/collections/{collection}', [\App\Http\Controllers\Operator\CollectionController::class, 'destroy'])->name('collections.destroy');

    Route::get('/search/synonyms', [\App\Http\Controllers\Operator\SearchTuningController::class, 'synonyms'])->name('search.synonyms');
    Route::post('/search/synonyms', [\App\Http\Controllers\Operator\SearchTuningController::class, 'storeSynonym'])->name('search.synonyms.store');
    Route::delete('/search/synonyms/{synonym}', [\App\Http\Controllers\Operator\SearchTuningController::class, 'destroySynonym'])->name('search.synonyms.destroy');
    Route::get('/search/queries', [\App\Http\Controllers\Operator\SearchTuningController::class, 'searches'])->name('search.queries');
    Route::get('/search/complements', [\App\Http\Controllers\Operator\SearchTuningController::class, 'complements'])->name('search.complements');
    Route::post('/search/complements', [\App\Http\Controllers\Operator\SearchTuningController::class, 'storeComplement'])->name('search.complements.store');
    Route::delete('/search/complements/{rule}', [\App\Http\Controllers\Operator\SearchTuningController::class, 'destroyComplement'])->name('search.complements.destroy')->whereNumber('rule');

    Route::get('/catalogue/categories', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'categories'])->name('catalogue.categories');
    Route::post('/catalogue/categories', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'storeCategory'])->name('catalogue.categories.store');
    Route::patch('/catalogue/categories/{category}', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'updateCategory'])->name('catalogue.categories.update');
    Route::post('/catalogue/categories/{category}/attributes', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'storeAttribute'])->name('catalogue.attributes.store');
    Route::delete('/catalogue/categories/{category}/attributes/{attribute}', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'destroyAttribute'])->name('catalogue.attributes.destroy');
    Route::get('/catalogue/featured', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'featured'])->name('catalogue.featured');
    Route::post('/catalogue/featured', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'addFeatured'])->name('catalogue.featured.add');
    Route::delete('/catalogue/featured/{store}', [\App\Http\Controllers\Operator\CatalogueAdminController::class, 'removeFeatured'])->name('catalogue.featured.remove');

    // People: support lookup (each opening is access-logged), operator access.
    Route::get('/people', [\App\Http\Controllers\Operator\PeopleController::class, 'index'])->name('people.index');
    Route::get('/people/users/{user}', [\App\Http\Controllers\Operator\PeopleController::class, 'user'])->name('people.user');
    Route::get('/people/stores/{store}', [\App\Http\Controllers\Operator\PeopleController::class, 'store'])->name('people.store');
    Route::get('/people/orders/{order}', [\App\Http\Controllers\Operator\PeopleController::class, 'order'])->name('people.order');
    Route::get('/operators', [\App\Http\Controllers\Operator\PeopleController::class, 'operators'])->name('team');
    Route::post('/operators', [\App\Http\Controllers\Operator\PeopleController::class, 'grant'])->name('team.grant');
    Route::post('/operators/{user}/revoke', [\App\Http\Controllers\Operator\PeopleController::class, 'revoke'])->name('team.revoke');

    Route::get('/health', \App\Http\Controllers\Operator\HealthController::class)->name('health');
    Route::get('/audit', \App\Http\Controllers\Operator\AuditController::class)->name('audit');
});
// --- end w7 ---

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
