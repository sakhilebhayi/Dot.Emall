<?php

namespace App\Http\Controllers\Seller;

use App\Models\ContentReport;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Services\Community\ProductQuestions;
use App\Services\Community\ReviewResponses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Questions from shoppers, reviews of the store's products and the reports made against its listings' reviews and questions.
 * A seller answers a question and replies once to a review; they cannot delete or hide anything (only the Emall team can).
 */
class FeedbackController extends SellerController
{
    public function index(Request $request): View
    {
        $store = $this->myStore($request);
        $productIds = Product::withTrashed()->where('store_id', $store->id)->select('id');
        $tab = in_array($request->query('tab'), ['questions', 'reviews', 'reports'], true) ? $request->query('tab') : 'questions';

        $questions = ProductQuestion::query()->published()->whereIn('product_id', $productIds)
            ->with(['product:id,name', 'user:id,name', 'answers' => fn ($a) => $a->published()])
            ->orderByRaw('CASE WHEN answers_count = 0 THEN 0 ELSE 1 END')->orderByDesc('id')->limit(40)->get();

        $reviews = Review::query()->published()->whereIn('product_id', $productIds)
            ->with(['product:id,name', 'user:id,name', 'response'])
            ->orderByRaw('(SELECT COUNT(*) FROM review_responses WHERE review_responses.review_id = reviews.id)')->orderByDesc('id')->limit(40)->get();

        // Reports made against this store's own reviews and questions. Reports never hide anything by themselves: the Emall team decides.
        $reviewIds = Review::query()->whereIn('product_id', $productIds)->select('id');
        $questionIds = ProductQuestion::query()->whereIn('product_id', $productIds)->select('id');
        $reports = ContentReport::query()->where(function ($q) use ($reviewIds, $questionIds) {
            $q->where(fn ($r) => $r->where('reportable_type', 'review')->whereIn('reportable_id', $reviewIds))
                ->orWhere(fn ($r) => $r->where('reportable_type', 'question')->whereIn('reportable_id', $questionIds));
        })->latest('id')->limit(40)->get();

        return view('seller.feedback', [
            'store' => $store,
            'tab' => $tab,
            'questions' => $questions,
            'reviews' => $reviews,
            'reports' => $reports,
            'unanswered' => $questions->where('answers_count', 0)->count(),
            'unreplied' => $reviews->filter(fn ($r) => $r->response === null)->count(),
        ]);
    }

    public function answer(Request $request, ProductQuestion $question, ProductQuestions $questions): RedirectResponse
    {
        $this->owns($request, $question->product_id);
        $data = $request->validate(['body' => 'required|string|max:1500']);

        try {
            $questions->answer($request->user(), $question, $data['body']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('seller.feedback', ['tab' => 'questions'])->with('status', 'Answer posted. The shopper has been told.');
    }

    public function respond(Request $request, Review $review, ReviewResponses $responses): RedirectResponse
    {
        $this->owns($request, $review->product_id);
        $data = $request->validate(['body' => 'required|string|max:1000']);

        try {
            $responses->respond($request->user(), $review, $data['body']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('seller.feedback', ['tab' => 'reviews'])->with('status', 'Reply posted. It appears beside the review and cannot be edited, so read it once more next time before you send.');
    }

    private function owns(Request $request, int $productId): void
    {
        $store = $this->myStore($request);
        abort_unless(Product::withTrashed()->whereKey($productId)->where('store_id', $store->id)->exists(), 404);
    }
}
