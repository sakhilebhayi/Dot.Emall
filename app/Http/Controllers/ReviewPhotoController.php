<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Services\Community\ReviewPhotos;
use App\Services\Community\StoreMembers;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a review photo from the private disk. A photo is visible when its review is published and its product is
 * still on sale; the author, the product's own store and the operator can also see it while the review is held.
 */
class ReviewPhotoController extends Controller
{
    public function __invoke(ReviewPhoto $photo, StoreMembers $members): StreamedResponse
    {
        $review = Review::query()->findOrFail($photo->review_id);
        $user = auth()->user();

        $public = $review->status === Review::PUBLISHED && Product::query()->browsable()->whereKey($review->product_id)->exists();

        if (! $public) {
            $store = Product::withTrashed()->with('store')->find($review->product_id)?->store;

            abort_unless(
                $user && ($user->id === $review->user_id || $members->isOperator($user) || ($store && $members->canSpeakFor($user, $store))),
                404,
            );
        }

        $disk = Storage::disk(ReviewPhotos::DISK);
        abort_unless($disk->exists($photo->path), 404);

        return $disk->response($photo->path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => $public ? 'public, max-age=86400' : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
