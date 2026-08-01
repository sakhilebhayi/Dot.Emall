<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Notifications\NewReviewNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Product reviews (platform-loop pass). The Review model, migration, and
 * unique(product_id, user_id) constraint already existed but no route
 * could ever create one. Authorization delegated to ReviewPolicy.
 */
class ReviewController extends Controller
{
    public function store(Request $request, Product $product): RedirectResponse
    {
        Gate::authorize('create', Review::class);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body'   => ['nullable', 'string', 'max:2000'],
        ]);

        // The DB unique constraint on (product_id, user_id) is the source
        // of truth; this check exists only to return a friendly validation
        // error instead of a raw constraint-violation exception.
        $alreadyReviewed = Review::where('product_id', $product->id)
            ->where('user_id', auth()->id())
            ->exists();

        if ($alreadyReviewed) {
            return back()->withErrors(['rating' => 'You have already reviewed this product.']);
        }

        $review = Review::create([
            'product_id' => $product->id,
            'user_id'    => auth()->id(),
            'rating'     => $validated['rating'],
            'body'       => $validated['body'] ?? null,
        ]);

        $product->loadMissing('store.owner');
        $product->store->owner?->notify(new NewReviewNotification($review));

        return redirect()->route('products.show', $product)->with('status', 'Review posted.');
    }

    public function destroy(Review $review): RedirectResponse
    {
        Gate::authorize('delete', $review);

        $productId = $review->product_id;
        $review->delete();

        return redirect()->route('products.show', $productId)->with('status', 'Review deleted.');
    }
}
