<?php

namespace App\Services\Search;

use App\Contracts\CategoryTree;
use App\Models\Product;
use App\Support\Shop;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Everything the two engines share: understanding the request, the visibility rules, filters, facets, sorts,
 * pagination, typo correction, the retry ladder and the "why this matched" notes. An engine only supplies how text
 * is matched and scored.
 *
 * Visibility is always live: a listing is searchable when it is not archived, active, approved, and its store is
 * active. Price, stock and rating are read from `products` at query time, so a purchase or a review changes a
 * result immediately without anyone re-indexing.
 *
 * The retry ladder, stopping at the first rung that finds something:
 *   1. the request with the meaning read out of it (brand, category, price, attributes become filters)
 *   2. the same words taken literally, ignoring what was inferred (inferred filters never hide results)
 *   3. misspelt words corrected against the catalogue's own vocabulary, then rungs 1 and 2 again
 *   4. any of the words, ranked by how many match, flagged as relaxed
 */
abstract class AbstractSearch implements SearchEngine
{
    private const FIELDS = [
        'title' => 'title_n',
        'brand' => 'brand_n',
        'category' => 'category_n',
        'attributes' => 'attributes_n',
        'store' => 'store_n',
        'description' => 'description_n',
    ];

    public function __construct(
        protected readonly QueryUnderstanding $understanding,
        protected readonly Vocabulary $vocabulary,
        protected readonly CategoryTree $categories,
    ) {}

    /** Adds the "this row matches these words" condition. */
    abstract protected function whereText(Builder $query, array $slots, string $mode): void;

    /**
     * The text-relevance part of the score.
     *
     * @return array{0: string, 1: list<mixed>} SQL fragment and bindings
     */
    abstract protected function textScore(array $slots, string $mode): array;

    public function search(SearchRequest $request): SearchResult
    {
        $understood = [];
        $notes = [];
        $correctedFrom = null;
        $didYouMean = null;
        $relaxed = false;

        $parsed = null;
        $mode = 'all';

        if ($request->text !== '') {
            $parsed = $request->understand ? $this->understanding->parse($request->text) : $this->understanding->literal($request->text);
        }

        [$effective, $inferredKeys] = $this->merge($request, $parsed);
        $slots = $parsed?->slots ?? [];
        $chosen = null;

        if ($parsed === null || ($slots === [] && $parsed->tokens === [] && $inferredKeys === [])) {
            // No usable words: a plain browse with the filters.
            $chosen = ['filters' => $effective, 'slots' => [], 'mode' => 'all', 'inferred' => $inferredKeys];
        } else {
            $chosen = $this->ladder($request, $effective, $inferredKeys, $parsed, $correctedFrom, $didYouMean, $relaxed, $notes);

            if ($chosen === null) {
                return $this->empty($request, $effective, $inferredKeys, $parsed, $didYouMean);
            }

            $parsed = $chosen['parsed'] ?? $parsed;
        }

        $understood = $parsed?->detected ?? [];

        return $this->build($request, $chosen, $parsed, $understood, $notes, $correctedFrom, $didYouMean, $relaxed);
    }

    /**
     * @return array{filters: SearchRequest, slots: array, mode: string, inferred: list<string>, parsed?: ParsedQuery}|null
     */
    private function ladder(SearchRequest $request, SearchRequest $effective, array $inferredKeys, ParsedQuery $parsed, ?string &$correctedFrom, ?string &$didYouMean, bool &$relaxed, array &$notes): ?array
    {
        $try = function (ParsedQuery $p, SearchRequest $withInferred, array $keys) use ($request, &$notes): ?array {
            if ($this->count($withInferred, $p->slots, 'all') > 0) {
                return ['filters' => $withInferred, 'slots' => $p->slots, 'mode' => 'all', 'inferred' => $keys, 'parsed' => $p];
            }

            if ($keys !== [] && $p->fullSlots !== []) {
                if ($this->count($request, $p->fullSlots, 'all') > 0) {
                    $notes[] = 'No exact matches with the filters read from your search, so those were left out.';

                    return ['filters' => $request, 'slots' => $p->fullSlots, 'mode' => 'all', 'inferred' => [], 'parsed' => $p];
                }
            }

            return null;
        };

        if ($found = $try($parsed, $effective, $inferredKeys)) {
            return $found;
        }

        // Rung 3: correct words the catalogue has never seen.
        $replacements = $this->corrections($parsed);

        if ($replacements !== []) {
            $corrected = $this->understanding->parseCorrected($request->text, $replacements);
            [$correctedEffective, $correctedKeys] = $this->merge($request, $corrected);

            if ($found = $try($corrected, $correctedEffective, $correctedKeys)) {
                $correctedFrom = $request->text;
                $didYouMean = $corrected->normalised !== '' ? $this->display($corrected, $replacements) : null;
                $notes[] = 'Showing results for "'.($didYouMean ?? $corrected->normalised).'" instead of "'.$request->text.'".';

                return $found;
            }

            $parsed = $corrected;
            $didYouMean = $this->display($corrected, $replacements);
        }

        // Rung 4: any of the words.
        $any = $parsed->fullSlots !== [] ? $parsed->fullSlots : $parsed->slots;

        if (count($any) > 1 && $this->count($request, $any, 'any') > 0) {
            $relaxed = true;
            $notes[] = 'Nothing matched all of your words, so these match some of them.';

            return ['filters' => $request, 'slots' => $any, 'mode' => 'any', 'inferred' => [], 'parsed' => $parsed];
        }

        return null;
    }

    /**
     * @return array<string, string> wrong word => right word, for words the catalogue does not contain
     */
    private function corrections(ParsedQuery $parsed): array
    {
        $out = [];
        $known = array_merge(array_keys($this->vocabulary->brands()), array_keys($this->vocabulary->categories()));

        foreach (array_slice(array_unique($parsed->tokens), 0, 6) as $token) {
            if (in_array($token, $known, true) || $this->vocabulary->knows($token) || $this->numeric($token)) {
                continue;
            }

            if (($fix = $this->vocabulary->correct($token)) !== null) {
                $out[$token] = $fix;
            }
        }

        return $out;
    }

    private function numeric(string $token): bool
    {
        return preg_match('/\d/', $token) === 1;
    }

    private function display(ParsedQuery $corrected, array $replacements): string
    {
        return $corrected->normalised;
    }

    /**
     * Applies what was understood to the explicit filters; the person's own filters always win.
     *
     * @return array{0: SearchRequest, 1: list<string>}
     */
    private function merge(SearchRequest $request, ?ParsedQuery $parsed): array
    {
        $effective = clone $request;
        $keys = [];

        if ($parsed === null) {
            return [$effective, $keys];
        }

        $inferred = $parsed->inferred;

        if ($effective->categoryId === null && isset($inferred['category_id'])) {
            $effective->categoryId = (int) $inferred['category_id'];
            $keys[] = 'category';
        }

        if ($effective->brands === [] && isset($inferred['brand'])) {
            $effective->brands = [$inferred['brand']];
            $keys[] = 'brand';
        }

        if ($effective->priceMin === null && $effective->priceMax === null && (isset($inferred['price_min']) || isset($inferred['price_max']))) {
            $effective->priceMin = $inferred['price_min'] ?? null;
            $effective->priceMax = $inferred['price_max'] ?? null;
            $keys[] = 'price';
        }

        if (! $effective->inStock && ! empty($inferred['in_stock'])) {
            $effective->inStock = true;
            $keys[] = 'in_stock';
        }

        foreach ($inferred['attributes'] ?? [] as $key => $values) {
            if (! isset($effective->attributes[$key])) {
                $effective->attributes[$key] = $values;
                $keys[] = 'attr.'.$key;
            }
        }

        return [$effective, $keys];
    }

    // --- query building -----------------------------------------------------------------------------------------

    protected function base(): Builder
    {
        return DB::table('search_documents as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->join('stores as s', 's.id', '=', 'p.store_id')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->where('p.status', 'approved')
            ->where('s.is_active', true);
    }

    /**
     * @param  list<string>  $except  filter groups to leave out (category, brand, price, in_stock, rating, store, region, attrs, attr.<key>)
     */
    protected function query(SearchRequest $f, array $slots, string $mode, array $except = []): Builder
    {
        $q = $this->base();

        if ($slots !== []) {
            $this->whereText($q, $slots, $mode);
        }

        if ($f->categoryId !== null && ! in_array('category', $except, true)) {
            $q->whereIn('p.category_id', $this->categories->withDescendants($f->categoryId));
        }

        if ($f->brands !== [] && ! in_array('brand', $except, true)) {
            $q->whereIn('d.brand_n', array_map(fn ($b) => Text::index($b), $f->brands));
        }

        if (! in_array('price', $except, true)) {
            if ($f->priceMin !== null) {
                $q->where('p.price', '>=', $f->priceMin);
            }

            if ($f->priceMax !== null) {
                $q->where('p.price', '<=', $f->priceMax);
            }
        }

        if ($f->inStock && ! in_array('in_stock', $except, true)) {
            $q->where('p.stock', '>', 0);
        }

        if ($f->minRating !== null && ! in_array('rating', $except, true)) {
            $q->where('p.rating_count', '>=', 1)->where('p.rating_average', '>=', $f->minRating);
        }

        if ($f->storeIds !== [] && ! in_array('store', $except, true)) {
            $q->whereIn('p.store_id', $f->storeIds);
        }

        if ($f->regions !== [] && ! in_array('region', $except, true)) {
            $q->whereIn('d.region', $f->regions);
        }

        foreach ($f->attributes as $key => $values) {
            if (in_array('attrs', $except, true) || in_array('attr.'.$key, $except, true)) {
                continue;
            }

            $q->whereExists(function ($sub) use ($key, $values) {
                $sub->select(DB::raw('1'))->from('search_document_attributes as a')
                    ->whereColumn('a.product_id', 'd.product_id')
                    ->where('a.attr_key', $key)
                    ->whereIn('a.value_n', array_map(fn ($v) => Text::index($v), $values));
            });
        }

        return $q;
    }

    protected function count(SearchRequest $f, array $slots, string $mode): int
    {
        return (int) $this->query($f, $slots, $mode)->count();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    protected function score(array $slots, string $mode, string $normalised): array
    {
        $min = (int) config('discovery.search.rating_min_reviews', 3);
        $parts = [];
        $bindings = [];

        if ($slots !== []) {
            [$sql, $b] = $this->textScore($slots, $mode);
            $parts[] = $sql;
            array_push($bindings, ...$b);

            $parts[] = 'CASE WHEN d.title_n = ? THEN 6 WHEN d.title_n LIKE ? THEN 3 ELSE 0 END';
            array_push($bindings, $normalised, $this->likeEscape($normalised).'%');
        }

        $parts[] = 'CASE WHEN p.stock > 0 THEN 1.5 ELSE 0 END';
        // Reviews move the order only when there are enough of them to mean something (and a poor average lowers it).
        $parts[] = 'CASE WHEN p.rating_count >= ? THEN (p.rating_average - 3) * 0.8 * (CASE WHEN p.rating_count >= 10 THEN 1.0 ELSE p.rating_count / 10.0 END) ELSE 0 END';
        $bindings[] = $min;

        return ['('.implode(' + ', $parts).')', $bindings];
    }

    protected function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array{0: string, 1: list<mixed>, 2: string|null} order-by SQL, bindings, a note about the order
     */
    private function ordering(SearchRequest $f, array $slots, string $mode, string $normalised, bool $relaxed): array
    {
        $min = (int) config('discovery.search.rating_min_reviews', 3);
        $units = (int) config('discovery.search.best_seller_min_units', 3);

        return match ($f->sort) {
            'price_asc' => ['p.price ASC, p.id DESC', [], null],
            'price_desc' => ['p.price DESC, p.id DESC', [], null],
            'newest' => ['p.created_at DESC, p.id DESC', [], null],
            'rating' => [
                'CASE WHEN p.rating_count >= ? THEN 1 ELSE 0 END DESC, p.rating_average DESC, p.rating_count DESC, p.id DESC',
                [$min],
                "Rated by real reviews. Products with fewer than {$min} reviews are listed after those with enough.",
            ],
            'best_selling' => [
                'CASE WHEN d.sold_count >= ? THEN 1 ELSE 0 END DESC, CASE WHEN d.sold_count >= ? THEN d.sold_count ELSE 0 END DESC, p.created_at DESC, p.id DESC',
                [$units, $units],
                "Best selling counts units in paid orders. Only products with at least {$units} sold are ranked by it; the rest follow, newest first.",
            ],
            default => $slots !== []
                ? ['score DESC, p.id DESC', [], $relaxed ? 'Ranked by how many of your words each product matches.' : null]
                : ['CASE WHEN p.stock > 0 THEN 1 ELSE 0 END DESC, p.created_at DESC, p.id DESC', [], 'No search words: in-stock products first, then newest.'],
        };
    }

    // --- building the result ------------------------------------------------------------------------------------

    private function build(SearchRequest $request, array $chosen, ?ParsedQuery $parsed, array $understood, array $notes, ?string $correctedFrom, ?string $didYouMean, bool $relaxed): SearchResult
    {
        /** @var SearchRequest $f */
        $f = $chosen['filters'];
        $slots = $chosen['slots'];
        $mode = $chosen['mode'];

        $total = $this->count($f, $slots, $mode);

        // A word that became a filter ("tv" became the category "TV, audio and video") must still decide the ORDER: it no longer
        // narrows the result, but a product that says "TV" in its title comes before one that is only in the same category
        // (D-190). The rank slots score; they never exclude.
        $rank = $slots !== [] ? $slots : ($parsed !== null && $parsed->hasInferredFilters() ? $parsed->fullSlots : []);
        $normalised = $rank === [] ? '' : implode(' ', array_map(fn ($s) => $s[0], $rank));
        [$orderSql, $orderBindings, $sortNote] = $this->ordering($f, $rank, $mode, $normalised, $relaxed);

        $page = min($f->page, max(1, (int) ceil($total / $f->perPage)));
        $select = $this->query($f, $slots, $mode)
            ->select('d.product_id', 'd.brand', 'd.title_n', 'd.brand_n', 'd.category_n', 'd.attributes_n', 'd.store_n', 'p.stock')
            ->selectRaw('substr(d.description_n, 1, 1500) as description_n');

        [$scoreSql, $scoreBindings] = $this->score($rank, $mode, $normalised);
        $select->selectRaw($scoreSql.' as score', $scoreBindings)
            ->orderByRaw($orderSql, $orderBindings)
            ->offset(($page - 1) * $f->perPage)
            ->limit($f->perPage);

        $rows = $select->get();
        $products = Product::query()->whereIn('id', $rows->pluck('product_id'))->with(['store', 'images'])->get()->keyBy('id');

        $items = [];

        foreach ($rows as $row) {
            if (! ($product = $products->get($row->product_id))) {
                continue;
            }

            [$matched, $hitNotes] = $this->explain($row, $slots, $mode);

            $items[] = new SearchHit($product, round((float) $row->score, 3), $matched, $hitNotes, $row->brand, (int) $row->stock > 0);
        }

        return new SearchResult(
            items: $items,
            total: $total,
            page: $page,
            perPage: $f->perPage,
            facets: $this->facets($f, $slots, $mode),
            didYouMean: $didYouMean,
            correctedFrom: $correctedFrom,
            relaxed: $relaxed,
            appliedFilters: $this->applied($f, $chosen['inferred']),
            understood: $understood,
            engine: $this->name(),
            sort: $f->sort,
            sortNote: $sortNote,
            normalisedQuery: $normalised,
            notes: $notes,
        );
    }

    private function empty(SearchRequest $request, SearchRequest $effective, array $inferredKeys, ParsedQuery $parsed, ?string $didYouMean): SearchResult
    {
        $applied = $this->applied($request, []);

        return new SearchResult(
            items: [],
            total: 0,
            page: 1,
            perPage: $request->perPage,
            facets: [],
            didYouMean: $didYouMean,
            correctedFrom: null,
            relaxed: false,
            appliedFilters: $applied,
            understood: $parsed->detected,
            engine: $this->name(),
            sort: $request->sort,
            sortNote: null,
            normalisedQuery: $parsed->normalised,
            notes: ['Nothing in the catalogue matches this search.'],
            relaxations: $this->relaxations($request, $parsed),
        );
    }

    /**
     * With nothing found: what each applied filter is costing, so the page can offer "remove this and see N".
     *
     * @return list<array{remove: string, label: string, count: int}>
     */
    private function relaxations(SearchRequest $request, ParsedQuery $parsed): array
    {
        $slots = $parsed->fullSlots ?: $parsed->slots;
        $out = [];

        foreach ($this->applied($request, []) as $chip) {
            $without = $request->withoutFilter($chip['key']);
            $count = $this->count($without, $slots, 'all');

            if ($count > 0) {
                $out[] = ['remove' => $chip['key'], 'label' => $chip['label'], 'count' => $count];
            }
        }

        if ($out === [] && $slots !== []) {
            $count = $this->count($request->with(['categoryId' => null, 'brands' => [], 'priceMin' => null, 'priceMax' => null, 'inStock' => false, 'minRating' => null, 'storeIds' => [], 'regions' => [], 'attributes' => []]), $slots, 'any');

            if ($count > 0) {
                $out[] = ['remove' => 'all', 'label' => 'all filters, matching any word', 'count' => $count];
            }
        }

        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * @param  object  $row
     * @return array{0: list<string>, 1: list<string>}
     */
    private function explain($row, array $slots, string $mode): array
    {
        $matched = [];
        $notes = [];

        foreach ($slots as $alternatives) {
            foreach ($alternatives as $index => $alternative) {
                $words = explode(' ', $alternative);
                $hit = false;

                foreach (self::FIELDS as $label => $column) {
                    $text = (string) ($row->{$column} ?? '');

                    if ($text === '') {
                        continue;
                    }

                    $all = true;

                    foreach ($words as $word) {
                        if (! Text::hasWord($text, $word)) {
                            $all = false;
                            break;
                        }
                    }

                    if ($all) {
                        $matched[$label] = true;
                        $hit = true;
                    }
                }

                if ($hit && $index > 0) {
                    $notes[] = '"'.$alternative.'" counts as "'.$alternatives[0].'"';
                    break;
                }
            }
        }

        $ordered = array_values(array_filter(array_keys(self::FIELDS), fn ($label) => isset($matched[$label])));

        return [$ordered, array_values(array_unique($notes))];
    }

    // --- chips --------------------------------------------------------------------------------------------------

    /**
     * @param  list<string>  $inferred
     * @return list<array{key: string, label: string, value: string, inferred: bool}>
     */
    private function applied(SearchRequest $f, array $inferred): array
    {
        $chips = [];
        $flag = fn (string $key) => in_array($key, $inferred, true);

        if ($f->categoryId !== null) {
            $chips[] = ['key' => 'category', 'label' => $this->categories->find($f->categoryId)['name'] ?? 'Category', 'value' => (string) $f->categoryId, 'inferred' => $flag('category')];
        }

        foreach ($f->brands as $brand) {
            $chips[] = ['key' => 'brand', 'label' => $brand, 'value' => $brand, 'inferred' => $flag('brand')];
        }

        if ($f->priceMin !== null || $f->priceMax !== null) {
            $label = match (true) {
                $f->priceMin !== null && $f->priceMax !== null => $this->money($f->priceMin).' to '.$this->money($f->priceMax),
                $f->priceMax !== null => 'Up to '.$this->money($f->priceMax),
                default => 'From '.$this->money((float) $f->priceMin),
            };
            $chips[] = ['key' => 'price', 'label' => $label, 'value' => ($f->priceMin ?? '').'-'.($f->priceMax ?? ''), 'inferred' => $flag('price')];
        }

        if ($f->inStock) {
            $chips[] = ['key' => 'in_stock', 'label' => 'In stock', 'value' => '1', 'inferred' => $flag('in_stock')];
        }

        if ($f->minRating !== null) {
            $chips[] = ['key' => 'rating', 'label' => $f->minRating.' stars and up', 'value' => (string) $f->minRating, 'inferred' => false];
        }

        if ($f->storeIds !== []) {
            $names = DB::table('stores')->whereIn('id', $f->storeIds)->pluck('name', 'id');

            foreach ($f->storeIds as $id) {
                $chips[] = ['key' => 'store', 'label' => (string) ($names[$id] ?? 'Store'), 'value' => (string) $id, 'inferred' => false];
            }
        }

        foreach ($f->regions as $region) {
            $chips[] = ['key' => 'region', 'label' => $region, 'value' => $region, 'inferred' => false];
        }

        foreach ($f->attributes as $key => $values) {
            foreach ($values as $value) {
                $chips[] = ['key' => 'attr.'.$key, 'label' => ucfirst(str_replace('_', ' ', $key)).': '.$value, 'value' => $value, 'inferred' => $flag('attr.'.$key)];
            }
        }

        return $chips;
    }

    private function money(float $amount): string
    {
        return Shop::amount(number_format($amount, 2, '.', ''));
    }

    // --- facets -------------------------------------------------------------------------------------------------

    /**
     * Counts for every filter, each computed with the OTHER filters applied but not its own (so choosing a brand
     * still shows the other brands and what they would give). All counts are real rows.
     *
     * @return array<string, mixed>
     */
    protected function facets(SearchRequest $f, array $slots, string $mode): array
    {
        $ttl = (int) config('discovery.search.facet_cache_seconds', 0);

        if ($ttl <= 0) {
            return $this->computeFacets($f, $slots, $mode);
        }

        $key = 'discovery.facets.'.sha1($f->signature().json_encode([$slots, $mode]));

        try {
            return Cache::remember($key, $ttl, fn () => $this->computeFacets($f, $slots, $mode));
        } catch (Throwable) {
            return $this->computeFacets($f, $slots, $mode);
        }
    }

    /** @return array<string, mixed> */
    private function computeFacets(SearchRequest $f, array $slots, string $mode): array
    {
        $limit = (int) config('discovery.search.max_facet_values', 20);

        $categoryRows = $this->query($f, $slots, $mode, ['category'])
            ->whereNotNull('p.category_id')
            ->selectRaw('p.category_id as k, count(*) as c')->groupBy('p.category_id')->orderByDesc('c')->limit($limit)->get();
        $names = $this->categories->many($categoryRows->pluck('k')->map(fn ($k) => (int) $k)->all());
        $selectedCategories = $f->categoryId !== null ? $this->categories->withDescendants($f->categoryId) : [];

        $brandRows = $this->query($f, $slots, $mode, ['brand'])
            ->whereNotNull('d.brand_n')
            ->selectRaw('d.brand_n as k, max(d.brand) as label, count(*) as c')->groupBy('d.brand_n')->orderByDesc('c')->orderBy('k')->limit($limit)->get();
        $selectedBrands = array_map(fn ($b) => Text::index($b), $f->brands);

        $storeRows = $this->query($f, $slots, $mode, ['store'])
            ->selectRaw('p.store_id as k, max(s.name) as label, count(*) as c')->groupBy('p.store_id')->orderByDesc('c')->limit($limit)->get();

        $regionRows = $this->query($f, $slots, $mode, ['region'])
            ->whereNotNull('d.region')
            ->selectRaw('d.region as k, count(*) as c')->groupBy('d.region')->orderByDesc('c')->limit($limit)->get();

        $stock = $this->query($f, $slots, $mode, ['in_stock'])
            ->selectRaw('count(*) as total, sum(case when p.stock > 0 then 1 else 0 end) as in_stock')->first();

        $rating = $this->query($f, $slots, $mode, ['rating'])
            ->selectRaw('sum(case when p.rating_count >= 1 and p.rating_average >= 4 then 1 else 0 end) as r4, sum(case when p.rating_count >= 1 and p.rating_average >= 3 then 1 else 0 end) as r3')->first();

        return [
            'categories' => $categoryRows->map(fn ($r) => [
                'id' => (int) $r->k,
                'name' => $names[(int) $r->k]['name'] ?? 'Category',
                'slug' => $names[(int) $r->k]['slug'] ?? '',
                'count' => (int) $r->c,
                'selected' => in_array((int) $r->k, $selectedCategories, true),
            ])->all(),
            'brands' => $brandRows->map(fn ($r) => [
                'value' => $r->label,
                'label' => $r->label,
                'count' => (int) $r->c,
                'selected' => in_array($r->k, $selectedBrands, true),
            ])->all(),
            'price' => $this->priceFacet($f, $slots, $mode),
            'availability' => [
                'in_stock' => (int) ($stock->in_stock ?? 0),
                'out_of_stock' => (int) ($stock->total ?? 0) - (int) ($stock->in_stock ?? 0),
                'selected' => $f->inStock,
            ],
            'rating' => [
                ['min' => 4, 'count' => (int) ($rating->r4 ?? 0), 'selected' => $f->minRating === 4],
                ['min' => 3, 'count' => (int) ($rating->r3 ?? 0), 'selected' => $f->minRating === 3],
            ],
            'stores' => $storeRows->map(fn ($r) => [
                'id' => (int) $r->k,
                'name' => $r->label,
                'count' => (int) $r->c,
                'selected' => in_array((int) $r->k, $f->storeIds, true),
            ])->all(),
            'regions' => $regionRows->map(fn ($r) => [
                'value' => $r->k,
                'count' => (int) $r->c,
                'selected' => in_array($r->k, $f->regions, true),
            ])->all(),
            'attributes' => $this->attributeFacets($f, $slots, $mode),
        ];
    }

    /**
     * Ranges taken from the real lowest and highest price in the result, in steps of 1, 2, 2.5 or 5 times a power of
     * ten. Never a fixed ladder of "Under R500 / R500 to R1000".
     *
     * @return array{min: float, max: float, buckets: list<array{from: float, to: float, count: int}>}|null
     */
    private function priceFacet(SearchRequest $f, array $slots, string $mode): ?array
    {
        $range = $this->query($f, $slots, $mode, ['price'])->selectRaw('min(p.price) as lo, max(p.price) as hi')->first();

        if ($range === null || $range->lo === null) {
            return null;
        }

        $lo = (float) $range->lo;
        $hi = (float) $range->hi;
        $facet = ['min' => $lo, 'max' => $hi, 'buckets' => []];

        if ($hi <= $lo) {
            return $facet;
        }

        $raw = ($hi - $lo) / 5;
        $magnitude = 10 ** floor(log10($raw));
        $step = $magnitude;

        foreach ([1, 2, 2.5, 5, 10] as $multiple) {
            $step = $magnitude * $multiple;

            if ($step >= $raw) {
                break;
            }
        }

        $start = floor($lo / $step) * $step;
        $edges = [];

        for ($edge = $start; $edge < $hi + $step && count($edges) < 8; $edge += $step) {
            $edges[] = round($edge, 2);
        }

        $query = $this->query($f, $slots, $mode, ['price']);
        $bindings = [];
        $cases = [];

        for ($i = 0; $i < count($edges) - 1; $i++) {
            $upper = $i === count($edges) - 2 ? '<=' : '<';
            $cases[] = "sum(case when p.price >= ? and p.price {$upper} ? then 1 else 0 end) as b{$i}";
            array_push($bindings, $edges[$i], $edges[$i + 1]);
        }

        $counts = $query->selectRaw(implode(', ', $cases), $bindings)->first();

        for ($i = 0; $i < count($edges) - 1; $i++) {
            $count = (int) ($counts->{'b'.$i} ?? 0);

            if ($count > 0) {
                $facet['buckets'][] = ['from' => $edges[$i], 'to' => $edges[$i + 1], 'count' => $count];
            }
        }

        return $facet;
    }

    /**
     * Spec facets for the products in the result, shown when the result is narrowed to a category (otherwise a
     * "size" of a shirt and a "size" of a screen would be mixed). The most widely filled attributes come first.
     *
     * @return array<string, array{label: string, values: list<array{value: string, label: string, count: int, selected: bool}>}>
     */
    private function attributeFacets(SearchRequest $f, array $slots, string $mode): array
    {
        if ($f->categoryId === null || ! Schema::hasTable('search_document_attributes')) {
            return [];
        }

        $out = [];
        $rows = $this->query($f, $slots, $mode, ['attrs'])
            ->join('search_document_attributes as a', 'a.product_id', '=', 'd.product_id')
            ->selectRaw('a.attr_key as k, max(a.attr_label) as label, a.value_n as v, max(a.value_text) as text, count(distinct a.product_id) as c')
            ->groupBy('a.attr_key', 'a.value_n')
            ->orderByDesc('c')->get();

        $byKey = $rows->groupBy('k');
        $coverage = $byKey->map(fn ($values) => $values->sum('c'))->sortDesc()->take(6);

        foreach ($coverage->keys() as $key) {
            $values = $byKey[$key];

            // A key the person has filtered on is recounted with the OTHER keys applied, not its own.
            if (isset($f->attributes[$key])) {
                $values = $this->query($f, $slots, $mode, ['attr.'.$key])
                    ->join('search_document_attributes as a', 'a.product_id', '=', 'd.product_id')
                    ->where('a.attr_key', $key)
                    ->selectRaw('a.attr_key as k, max(a.attr_label) as label, a.value_n as v, max(a.value_text) as text, count(distinct a.product_id) as c')
                    ->groupBy('a.attr_key', 'a.value_n')->orderByDesc('c')->get();
            }

            $selected = array_map(fn ($v) => Text::index($v), $f->attributes[$key] ?? []);

            $out[$key] = [
                'label' => (string) $values->first()->label,
                'values' => $values->take(12)->map(fn ($r) => [
                    'value' => (string) $r->text,
                    'label' => (string) $r->text,
                    'count' => (int) $r->c,
                    'selected' => in_array($r->v, $selected, true),
                ])->values()->all(),
            ];
        }

        return $out;
    }
}
