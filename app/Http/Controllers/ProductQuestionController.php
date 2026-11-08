<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductAnswer;
use App\Models\ProductQuestion;
use App\Services\Community\ContentReports;
use App\Services\Community\ProductQuestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Product questions and answers. Asking needs a sign-in; only the store's own people answer; reports go to the operator queue. */
class ProductQuestionController extends Controller
{
    public function store(Request $request, Product $product, ProductQuestions $questions): RedirectResponse
    {
        abort_unless(Product::query()->browsable()->whereKey($product->id)->exists(), 404);
        Gate::authorize('create', [ProductQuestion::class, $product]);

        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);

        try {
            $question = $questions->ask($request->user(), $product, $data['body']);
        } catch (ValidationException $e) {
            return $this->back($product)->withErrors($e->errors(), 'ask')->withInput();
        }

        return $this->back($product, $question->id)->with('status', $question->status === ProductQuestion::PUBLISHED
            ? 'Your question is on the page. The store has been told.'
            : 'Thanks. Your question has a link or contact details in it, so it waits for a quick check before it shows.');
    }

    public function answer(Request $request, ProductQuestion $question, ProductQuestions $questions): RedirectResponse
    {
        Gate::authorize('answer', $question);

        $data = $request->validate(['body' => ['required', 'string', 'max:1500']]);

        try {
            $questions->answer($request->user(), $question, $data['body']);
        } catch (ValidationException $e) {
            return $this->back($question->product, $question->id)->withErrors($e->errors(), 'answer'.$question->id);
        }

        return $this->back($question->product, $question->id)->with('status', 'Your answer is on the page. The person who asked has been told.');
    }

    public function destroy(ProductQuestion $question, ProductQuestions $questions): RedirectResponse
    {
        Gate::authorize('delete', $question);

        try {
            $questions->withdraw($question);
        } catch (ValidationException $e) {
            return $this->back($question->product)->with('error', collect($e->errors())->flatten()->first());
        }

        return $this->back($question->product)->with('status', 'Your question is withdrawn.');
    }

    public function report(Request $request, ProductQuestion $question, ContentReports $reports, ReviewActionController $shared): RedirectResponse
    {
        Gate::authorize('report', $question);

        return $shared->file($request, $reports, 'question', $question->id, fn () => $this->back($question->product, $question->id), 'Thank you. The marketplace team will look at this question.');
    }

    public function reportAnswer(Request $request, ProductAnswer $answer, ContentReports $reports, ReviewActionController $shared): RedirectResponse
    {
        Gate::authorize('report', $answer);
        $answer->loadMissing('question.product');

        return $shared->file($request, $reports, 'answer', $answer->id, fn () => $this->back($answer->question->product, $answer->question_id), 'Thank you. The marketplace team will look at this answer.');
    }

    private function back(Product $product, ?int $questionId = null): RedirectResponse
    {
        return redirect()->to(route('products.show', $product).'#'.($questionId ? 'question-'.$questionId : 'questions'));
    }
}
