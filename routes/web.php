<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\DashboardController;
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
use App\Http\Controllers\SellerOrderController;
use App\Http\Controllers\SellerProductController;
use App\Http\Controllers\StoreController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->middleware('throttle:10,1')->name('ecosystem.auth');

// Paystack's server-to-server notifications. No session, no CSRF: the HMAC
// signature header is the authentication (see PaystackWebhookController).
Route::post('/webhooks/paystack', PaystackWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.paystack');

Route::get('/', function () {
    return view('welcome');
});

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
