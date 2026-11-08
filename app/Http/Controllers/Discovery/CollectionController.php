<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Services\Catalogue\CategoryTree;
use App\Services\Discovery\Collections;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use App\Support\CollectionText;
use Illuminate\View\View;

/**
 * Operator collections, public inside their dates. A manual one is a hand-picked list, a dynamic one is a rule re-run on every view
 * (so it never shows something that cannot be bought). Before its start date a collection does not exist for the public (404);
 * after its end it still answers, saying plainly that it ended.
 */
class CollectionController extends Controller
{
    private const SHOWN = 48;

    public function index(Collections $collections): View
    {
        return view('discover.collections', ['live' => $collections->live(24, 4)]);
    }

    public function show(string $slug, Collections $collections, SearchEngine $engine, CategoryTree $tree): View
    {
        $collection = Collection::query()->where('slug', $slug)->where('visibility', 'public')->firstOrFail();

        abort_if($collection->starts_at !== null && $collection->starts_at->isFuture(), 404);

        $ended = $collection->ends_at !== null && $collection->ends_at->lte(now());
        $products = $ended ? collect() : $collections->products($collection, self::SHOWN);
        $total = $products->count();
        $searchParams = null;

        if (! $ended && $collection->type === Collection::DYNAMIC) {
            $rule = (array) $collection->rule;
            $total = $engine->search(SearchRequest::fromArray(['literal' => '1'] + $rule)->with(['perPage' => 1, 'page' => 1]))->total;
            $searchParams = $rule + ['literal' => 1];
        }

        return view('discover.collection', [
            'collection' => $collection,
            'products' => $products,
            'total' => $total,
            'ended' => $ended,
            'window' => CollectionText::window($collection),
            'ruleText' => $collection->type === Collection::DYNAMIC ? CollectionText::rule((array) $collection->rule, $tree) : null,
            'searchParams' => $searchParams,
        ]);
    }
}
