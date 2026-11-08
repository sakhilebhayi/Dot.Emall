<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\ContentReport;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Services\Operator\OperatorDecisions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Reviews and questions held for a check. Release puts them on the page; removal needs a reason, which the author is shown. */
class ContentModerationController extends Controller
{
    use AnswersDecisions;

    public function index(Request $request): View
    {
        $kind = $request->query('kind') === 'questions' ? 'questions' : 'reviews';

        $items = $kind === 'reviews'
            ? Review::query()->where('status', Review::HELD)->with(['user:id,name,created_at', 'product:id,name,store_id', 'photos'])->orderBy('id')->paginate(20)->withQueryString()
            : ProductQuestion::query()->where('status', ProductQuestion::HELD)->with(['user:id,name,created_at', 'product:id,name'])->orderBy('id')->paginate(20)->withQueryString();

        return view('operator.moderation.index', [
            'kind' => $kind,
            'items' => $items,
            'heldReviews' => Review::query()->where('status', Review::HELD)->count(),
            'heldQuestions' => ProductQuestion::query()->where('status', ProductQuestion::HELD)->count(),
            'reportedReviews' => ContentReport::query()->where('status', 'open')->where('reportable_type', 'review')->distinct()->count('reportable_id'),
            'reportedQuestions' => ContentReport::query()->where('status', 'open')->whereIn('reportable_type', ['question', 'answer'])->distinct()->count('reportable_id'),
        ]);
    }

    public function releaseReview(Request $request, Review $review, OperatorDecisions $decisions): RedirectResponse
    {
        return $this->attempt('review-'.$review->id, fn () => $decisions->releaseReview($review, $request->user(), $request->input('reason')), 'Review released. It is on the product page now.');
    }

    public function removeReview(Request $request, Review $review, OperatorDecisions $decisions): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('review-'.$review->id, fn () => $decisions->removeReview($review->load('product', 'user'), $request->user(), $request->input('reason')), 'Review removed. The reviewer has been told why.');
    }

    public function releaseQuestion(Request $request, ProductQuestion $question, OperatorDecisions $decisions): RedirectResponse
    {
        return $this->attempt('question-'.$question->id, fn () => $decisions->releaseQuestion($question, $request->user(), $request->input('reason')), 'Question released. It is on the product page now.');
    }

    public function removeQuestion(Request $request, ProductQuestion $question, OperatorDecisions $decisions): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('question-'.$question->id, fn () => $decisions->removeQuestion($question->load('product', 'user'), $request->user(), $request->input('reason')), 'Question removed. The person who asked has been told why.');
    }

    public function removeAnswer(Request $request, ProductAnswer $answer, OperatorDecisions $decisions): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt('answer-'.$answer->id, fn () => $decisions->removeAnswer($answer, $request->user(), $request->input('reason')), 'Answer removed. Its author has been told why.');
    }
}
