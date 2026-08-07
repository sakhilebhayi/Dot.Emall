<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReviewController;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
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

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $userId = auth()->id();

        // Stores owned by the current user
        $stores = Store::where('owner_id', $userId)
            ->withCount('products')
            ->get();

        $storeIds = $stores->pluck('id');

        // Products belonging to those stores
        $productIds = Product::whereIn('store_id', $storeIds)->pluck('id');

        // Orders that contain at least one of this seller's products
        $orderIds = OrderItem::whereIn('product_id', $productIds)
            ->pluck('order_id')
            ->unique()
            ->values();

        $totalProducts = $productIds->count();
        $totalOrders = $orderIds->count();
        $totalRevenue = Order::whereIn('id', $orderIds)
            ->where('status', 'delivered')
            ->sum('total');
        $pendingOrders = Order::whereIn('id', $orderIds)
            ->where('status', 'pending')
            ->count();
        $recentOrders = Order::whereIn('id', $orderIds)
            ->with(['user', 'items.product.store'])
            ->latest()
            ->limit(8)
            ->get();

        // Additional widgets (platform-loop pass): reviews and low-stock
        // signal across the seller's own products, plus the buyer-side
        // "my orders" count so the dashboard reflects both roles a user
        // can occupy in this marketplace.
        $totalReviews = Review::whereIn('product_id', $productIds)->count();
        $avgRating = round((float) Review::whereIn('product_id', $productIds)->avg('rating'), 1);
        $lowStockCount = Product::whereIn('id', $productIds)
            ->where('is_active', true)
            ->where('stock', '<', 5)
            ->count();
        $myOrdersCount = Order::where('user_id', $userId)->count();

        return view('dashboard', compact(
            'stores', 'totalProducts', 'totalOrders',
            'totalRevenue', 'pendingOrders', 'recentOrders',
            'totalReviews', 'avgRating', 'lowStockCount', 'myOrdersCount'
        ));
    })->name('dashboard');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::post('/products/{product}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');

    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
    Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
    Route::delete('/cart/{cartItem}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::post('/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
});
