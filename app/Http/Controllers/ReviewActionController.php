<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Services\Community\ContentReports;
use App\Services\Community\ReviewPhotos;
use App\Services\Community\ReviewResponses;
use App\Services\Community\ReviewVotes;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** What a person does to a review beyond writing it: edit their own, vote it helpful, report it, and (for the store) reply once. */
class ReviewActionController extends Controller
{
    public function update(Request $request, Review $review, ReviewService $reviews, ReviewPhotos $photos): RedirectResponse
    {
        Gate::authorize('update', $review);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:4'],
            'photos.*' => ['file'],
        ]);

        $reviews->update($request->user(), $review, (int) $data['rating'], $data['body'] ?? null);

        $new = array_filter((array) $request->file('photos', []));

        if ($new !== []) {
            $photos->attach($review->refresh(), $new);
        }

        return $this->backToReviews($review)->with('status', $review->refresh()->status === Review::HELD ? 'Saved. Your review is waiting for a quick check before it shows again.' : 'Your review is updated.');
    }

    public function vote(Request $request, Review $review, ReviewVotes $votes): RedirectResponse|JsonResponse
    {
        Gate::authorize('vote', $review);

        $voted = $votes->toggle($request->user(), $review);
        $count = (int) $review->refresh()->helpful_count;

        if ($request->expectsJson()) {
            return response()->json(['voted' => $voted, 'count' => $count]);
        }

        return $this->backToReviews($review);
    }

    public function report(Request $request, Review $review, ContentReports $reports): RedirectResponse
    {
        Gate::authorize('report', $review);

        return $this->file($request, $reports, 'review', $review->id, fn () => $this->backToReviews($review), 'Thank you. The marketplace team will look at this review.');
    }

    public function respond(Request $request, Review $review, ReviewResponses $responses): RedirectResponse
    {
        Gate::authorize('respond', $review);

        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);

        try {
            $responses->respond($request->user(), $review, $data['body']);
        } catch (ValidationException $e) {
            return $this->backToReviews($review)->withErrors($e->errors(), 'reply'.$review->id);
        }

        return $this->backToReviews($review)->with('status', 'Your reply is on the review.');
    }

    /** Shared by every "Report" form on the page: reason + optional details, answered with a plain sentence. */
    public function file(Request $request, ContentReports $reports, string $type, int $id, callable $back, string $thanks): RedirectResponse
    {
        $data = $request->validateWithBag('report'.$type.$id, [
            'reason' => ['required', 'string', 'in:'.implode(',', \App\Models\ContentReport::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ], ['reason.required' => 'Choose what is wrong with it.', 'reason.in' => 'Choose what is wrong with it.']);

        try {
            $reports->report($request->user(), $type, $id, $data['reason'], $data['details'] ?? null);
        } catch (ValidationException $e) {
            return $back()->withErrors($e->errors(), 'report'.$type.$id);
        }

        return $back()->with('status', $thanks);
    }

    private function backToReviews(Review $review): RedirectResponse
    {
        return redirect()->to(route('products.show', $review->product_id).'#review-'.$review->id);
    }
}
