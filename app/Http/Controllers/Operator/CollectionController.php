<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\Collection;
use App\Models\Product;
use App\Models\StoreCategory;
use App\Services\Discovery\Collections;
use App\Services\Operator\OperatorAudit;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use App\Support\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Collections editor: a hand-picked list or a rule, with a live preview of what the rule matches, dates, and publish. */
class CollectionController extends Controller
{
    use AnswersDecisions;

    public function index(Collections $collections): View
    {
        $rows = Collection::query()->orderBy('position')->orderBy('id')->get();

        return view('operator.collections.index', ['collections' => $rows]);
    }

    public function create(): View
    {
        return view('operator.collections.form', $this->formData(new Collection(['type' => Collection::MANUAL, 'visibility' => 'draft'])));
    }

    public function store(Request $request, Collections $collections, OperatorAudit $audit): RedirectResponse
    {
        return $this->attempt('collection', function () use ($request, $collections, $audit, &$created) {
            $created = $collections->create($request->user(), $this->payload($request));
            $audit->record($request->user(), 'collection.create', $created, null, ['type' => $created->type, 'visibility' => $created->visibility]);
        }, 'Collection saved.', route('operator.collections.index'));
    }

    public function edit(Collection $collection): View
    {
        return view('operator.collections.form', $this->formData($collection));
    }

    public function update(Request $request, Collection $collection, Collections $collections, OperatorAudit $audit): RedirectResponse
    {
        return $this->attempt('collection', function () use ($request, $collection, $collections, $audit) {
            $before = $collection->only(['title', 'visibility', 'starts_at', 'ends_at']);
            $updated = $collections->update($request->user(), $collection, $this->payload($request));
            $audit->record($request->user(), 'collection.update', $updated, null, ['visibility' => $updated->visibility, 'was' => $before['visibility']]);
        }, 'Collection saved.', route('operator.collections.index'));
    }

    public function destroy(Request $request, Collection $collection, Collections $collections, OperatorAudit $audit): RedirectResponse
    {
        $audit->record($request->user(), 'collection.delete', $collection);
        $collections->delete($request->user(), $collection);

        return redirect()->route('operator.collections.index')->with('status', 'Collection deleted.');
    }

    /** What a rule would match right now, before it is saved: the same search the shop runs, so the preview cannot disagree with the page. */
    public function preview(Request $request, Collections $collections, SearchEngine $engine): JsonResponse
    {
        try {
            $rule = $collections->validateRule($this->rule($request));
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => $e->errors()['rule'][0] ?? 'That rule is not valid.'], 422);
        }

        $result = $engine->search(SearchRequest::fromArray(['literal' => '1', 'sort' => 'newest'] + $rule)->with(['perPage' => 12, 'page' => 1]));

        return response()->json([
            'ok' => true,
            'total' => $result->total,
            'items' => collect($result->items)->map(fn ($hit) => ['id' => $hit->product->id, 'name' => $hit->product->name, 'store' => $hit->product->store?->name, 'price' => Shop::amount($hit->product->price)])->values(),
        ]);
    }

    /** Finds buyable products by name for the manual list. */
    public function find(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        return response()->json(['items' => Product::query()->browsable()->with('store:id,name')->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%')->orderBy('name')->limit(10)->get()
            ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'store' => $p->store?->name, 'price' => Shop::amount($p->price)])->values()]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $type = $request->input('type') === Collection::DYNAMIC ? Collection::DYNAMIC : Collection::MANUAL;

        return [
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'type' => $type,
            'visibility' => $request->input('visibility', 'draft'),
            'starts_at' => $request->input('starts_at') ?: null,
            'ends_at' => $request->input('ends_at') ?: null,
            'rule' => $type === Collection::DYNAMIC ? $this->rule($request) : null,
            'product_ids' => $type === Collection::MANUAL ? preg_split('/[\s,]+/', (string) $request->input('product_ids'), -1, PREG_SPLIT_NO_EMPTY) : [],
        ];
    }

    /** @return array<string, mixed> */
    private function rule(Request $request): array
    {
        $r = (array) $request->input('rule', []);
        $rule = array_filter([
            'category' => $r['category'] ?? null,
            'brand' => $r['brand'] ?? null,
            'price_min' => $r['price_min'] ?? null,
            'price_max' => $r['price_max'] ?? null,
            'rating' => $r['rating'] ?? null,
            'sort' => $r['sort'] ?? null,
            'q' => $r['q'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($r['in_stock'])) {
            $rule['in_stock'] = '1';
        }

        return $rule;
    }

    /** @return array<string, mixed> */
    private function formData(Collection $collection): array
    {
        $ids = $collection->exists ? DB::table('collection_products')->where('collection_id', $collection->id)->orderBy('position')->pluck('product_id')->all() : [];

        return [
            'collection' => $collection,
            'productIds' => $ids,
            'categories' => StoreCategory::query()->where('is_active', true)->orderBy('path')->get(['id', 'name', 'depth']),
            'sorts' => SearchRequest::SORTS,
        ];
    }
}
