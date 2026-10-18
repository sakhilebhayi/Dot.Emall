<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Notifications\NewReviewNotification;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Product reviews. Verified purchase only (ReviewPolicy / ReviewService): a
 * delivered order line for the product, one review per product per buyer.
 */
class ReviewController extends Controller
{
    public function store(Request $request, Product $product, ReviewService $reviews): RedirectResponse
    {
        Gate::authorize('create', [Review::class, $product]);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $review = $reviews->submit($request->user(), $product, (int) $validated['rating'], $validated['body'] ?? null);

        $product->loadMissing('store.owner');
        $product->store->owner?->notify(new NewReviewNotification($review));

        return redirect()->route('products.show', $product)->with('status', 'Review posted.');
    }

    public function destroy(Review $review, ReviewService $reviews): RedirectResponse
    {
        Gate::authorize('delete', $review);

        $productId = $review->product_id;
        $reviews->delete($review);

        return redirect()->route('products.show', $productId)->with('status', 'Review deleted.');
    }
}
