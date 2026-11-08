<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\SearchSynonym;
use App\Models\StoreCategory;
use App\Services\Discovery\SearchLog;
use App\Services\Operator\OperatorAudit;
use App\Services\Search\QueryUnderstanding;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use App\Services\Search\Text;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** What people searched and what the shop understood by it: synonyms with a test box, searches that found nothing, popular searches, and the "often needed with" rules. */
class SearchTuningController extends Controller
{
    use AnswersDecisions;

    public function synonyms(Request $request, QueryUnderstanding $understanding, SearchEngine $engine): View
    {
        $groups = SearchSynonym::query()->orderBy('group_key')->orderBy('id')->get()->groupBy('group_key');
        $test = trim((string) $request->query('test', ''));
        $result = null;

        if ($test !== '') {
            $parsed = $understanding->parse($test);
            $found = $engine->search(SearchRequest::fromArray(['q' => $test]));
            $result = ['parsed' => $parsed, 'total' => $found->total, 'notes' => $found->notes, 'titles' => collect($found->items)->take(5)->map(fn ($h) => $h->product->name)->all()];
        }

        return view('operator.search.synonyms', [
            'groups' => $groups,
            'test' => $test,
            'result' => $result,
            'prefill' => Text::normalise((string) $request->query('term', '')),
        ]);
    }

    public function storeSynonym(Request $request, OperatorAudit $audit): RedirectResponse
    {
        $request->validate(['terms' => ['required', 'string', 'max:500'], 'join' => ['nullable', 'string', 'max:80']]);

        return $this->attempt('synonym', function () use ($request, $audit) {
            $terms = collect(preg_split('/[,\n]+/', (string) $request->input('terms'), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($t) => trim($t))->filter()->unique()->values();

            if ($terms->count() < 2) {
                throw ValidationException::withMessages(['terms' => 'Give at least two words or phrases that mean the same, separated by commas.']);
            }

            // Joining an existing group: the word typed in "join" decides which group the new words join.
            $join = trim((string) $request->input('join'));
            $groupKey = $join !== '' ? SearchSynonym::query()->where('term', Text::index($join))->value('group_key') : null;

            if ($join !== '' && $groupKey === null) {
                throw ValidationException::withMessages(['join' => 'No group contains "'.$join.'". Leave it empty to start a new group.']);
            }

            $groupKey ??= Str::slug($terms->first()).'-'.Str::lower(Str::random(4));

            DB::transaction(function () use ($terms, $groupKey, $request, $audit) {
                foreach ($terms as $term) {
                    SearchSynonym::query()->firstOrCreate(['group_key' => $groupKey, 'term' => Text::index($term)]);
                }
                $audit->record($request->user(), 'synonym.add', ['type' => 'synonym', 'id' => (int) SearchSynonym::query()->where('group_key', $groupKey)->min('id'), 'label' => $terms->implode(', ')], null, ['group' => $groupKey]);
            });
        }, 'Saved. Searches understand it straight away.', route('operator.search.synonyms'));
    }

    public function destroySynonym(Request $request, SearchSynonym $synonym, OperatorAudit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $synonym, $audit) {
            $audit->record($request->user(), 'synonym.remove', ['type' => 'synonym', 'id' => $synonym->id, 'label' => $synonym->term], null, ['group' => $synonym->group_key]);
            $synonym->delete();

            // A group of one word means nothing: drop the stranded word so the table never holds a synonym of nothing.
            if (SearchSynonym::query()->where('group_key', $synonym->group_key)->count() === 1) {
                SearchSynonym::query()->where('group_key', $synonym->group_key)->delete();
            }
        });

        return redirect()->route('operator.search.synonyms')->with('status', 'Removed.');
    }

    public function searches(Request $request, SearchLog $log): View
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;

        return view('operator.search.searches', [
            'days' => $days,
            'zero' => $log->zeroResults($days, 50),
            'popular' => $log->popular(25),
            'totals' => [
                'searches' => (int) DB::table('search_queries')->where('created_at', '>=', now()->subDays($days))->count(),
                'zero' => (int) DB::table('search_queries')->where('created_at', '>=', now()->subDays($days))->where('result_count', 0)->count(),
            ],
        ]);
    }

    public function complements(): View
    {
        $rules = DB::table('complementary_rules')->orderBy('category_slug')->orderBy('position')->orderBy('id')->get();
        $names = StoreCategory::query()->pluck('name', 'slug');

        return view('operator.search.complements', [
            'rules' => $rules,
            'names' => $names,
            'categories' => StoreCategory::query()->where('is_active', true)->orderBy('path')->get(['slug', 'name', 'depth']),
        ]);
    }

    public function storeComplement(Request $request, OperatorAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'category_slug' => ['required', 'string', 'exists:store_categories,slug'],
            'complement_slug' => ['required', 'string', 'exists:store_categories,slug', 'different:category_slug'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        if (DB::table('complementary_rules')->where('category_slug', $data['category_slug'])->where('complement_slug', $data['complement_slug'])->exists()) {
            return back()->withInput()->withErrors(['complement_slug' => 'That rule already exists.']);
        }

        DB::transaction(function () use ($data, $request, $audit) {
            $id = DB::table('complementary_rules')->insertGetId($data + ['note' => $data['note'] ?? null, 'position' => (int) DB::table('complementary_rules')->max('position') + 1, 'created_at' => now(), 'updated_at' => now()]);
            $audit->record($request->user(), 'complement.add', ['type' => 'complement', 'id' => $id, 'label' => $data['category_slug'].' → '.$data['complement_slug']], $data['note'] ?? null);
        });

        return back()->with('status', 'Rule added. It shows on matching product pages as our suggestion, not as measured.');
    }

    public function destroyComplement(Request $request, int $rule, OperatorAudit $audit): RedirectResponse
    {
        $row = DB::table('complementary_rules')->where('id', $rule)->first();
        abort_if($row === null, 404);

        DB::transaction(function () use ($row, $request, $audit) {
            DB::table('complementary_rules')->where('id', $row->id)->delete();
            $audit->record($request->user(), 'complement.remove', ['type' => 'complement', 'id' => $row->id, 'label' => $row->category_slug.' → '.$row->complement_slug]);
        });

        return back()->with('status', 'Rule removed.');
    }
}
