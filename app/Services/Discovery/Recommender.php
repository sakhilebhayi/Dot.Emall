<?php

namespace App\Services\Discovery;

use App\Contracts\CategoryTree;
use App\Models\Product;
use App\Support\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Recommendations from real data only. Each strategy is explicit, returns items WITH the basis and the number behind
 * them, and returns nothing at all (never filler) when the data is below its minimum:
 *
 *   boughtTogether      distinct counted orders that held both products, at least 3 (config), "Bought together in N orders"
 *   viewedTogether      distinct people who viewed both, at least 5 (config)
 *   complementary       an editorial rule table ("laptops: bags, mice"), labelled as ours, not measured
 *   sameCategoryAndBrand  other listings in the same category (and brand), labelled as exactly that
 *   recentlyViewed      the person's own last views
 *   becauseYouViewed / becauseYouBought   the strategies above applied to the person's own history
 *
 * Whatever the strategy, an item is dropped when it is out of stock, not browsable, the anchor product itself, or sold
 * by the person's own store. Co-purchase and co-view links are precomputed (RecommendationBuilder, scheduled) into
 * product_relations; a brand-new catalogue therefore returns an honest empty set.
 */
class Recommender
{
    public function __construct(
        private readonly RecentlyViewed $viewed,
        private readonly CountedOrders $counted,
        private readonly CategoryTree $categories,
    ) {}

    /**
     * Every block that has something honest to say about a product, in the order a shopper wants them.
     *
     * @return list<RecommendationSet>
     */
    public function forProduct(Product $product, ?Actor $actor = null): array
    {
        $actor ??= Actor::none();

        return array_values(array_filter([
            $this->boughtTogether($product, $actor),
            $this->viewedTogether($product, $actor),
            $this->complementary($product, $actor),
            $this->sameCategoryAndBrand($product, $actor),
        ], fn (?RecommendationSet $set) => $set !== null && ! $set->isEmpty()));
    }

    public function boughtTogether(Product $product, ?Actor $actor = null, int $limit = 6): ?RecommendationSet
    {
        $min = (int) config('discovery.recommendations.bought_together_min', 3);
        $links = $this->relations($product->id, 'bought_together', $min);

        $items = $this->items($links, $actor, $product->id, $limit, fn (int $support) => 'Bought together in '.$support.' '.($support === 1 ? 'order' : 'orders'));

        return $items === [] ? null : new RecommendationSet(
            'bought_together',
            'Bought together',
            "Counted from paid orders that held this product and the one shown. Shown only when at least {$min} orders agree.",
            $items,
        );
    }

    public function viewedTogether(Product $product, ?Actor $actor = null, int $limit = 6): ?RecommendationSet
    {
        $min = (int) config('discovery.recommendations.viewed_together_min', 5);
        $links = $this->relations($product->id, 'viewed_together', $min);

        $items = $this->items($links, $actor, $product->id, $limit, fn (int $support) => 'Also viewed by '.$support.' '.($support === 1 ? 'person' : 'people'));

        return $items === [] ? null : new RecommendationSet(
            'viewed_together',
            'Customers also viewed',
            "Counted from people who viewed this product and the one shown. Shown only when at least {$min} people did.",
            $items,
        );
    }

    /** Editorial: our rule says this kind of product is often needed with that kind. Not measured from shoppers. */
    public function complementary(Product $product, ?Actor $actor = null, int $limit = 6): ?RecommendationSet
    {
        if ($product->category_id === null || ! Schema::hasTable('complementary_rules')) {
            return null;
        }

        $category = $this->categories->find((int) $product->category_id);

        if ($category === null) {
            return null;
        }

        // Rules may be written against the category or any ancestor of it (a rule for "Laptops" applies to a gaming laptop).
        $slugs = [];

        for ($row = $category, $guard = 0; $row !== null && $guard++ < 12; $row = $row['parent_id'] ? $this->categories->find($row['parent_id']) : null) {
            $slugs[] = $row['slug'];
        }

        $rules = DB::table('complementary_rules')->whereIn('category_slug', $slugs)->orderBy('position')->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return null;
        }

        $items = [];
        $seen = [$product->id => true];

        foreach ($rules as $rule) {
            $target = $this->categories->findBySlug($rule->complement_slug);

            if ($target === null) {
                continue;
            }

            $ids = $this->topInCategories($this->categories->withDescendants($target['id']), 3)->all();
            $eligible = $this->eligible($ids, $actor ?? Actor::none(), $product->id);

            foreach ($ids as $id) {
                if (isset($seen[$id]) || ! $eligible->has($id)) {
                    continue;
                }

                $seen[$id] = true;
                $items[] = new RecommendedItem($eligible[$id], 'Often needed with '.strtolower($category['name']).' ('.$target['name'].'): our suggestion', null);

                if (count($items) >= $limit) {
                    break 2;
                }
            }
        }

        return $items === [] ? null : new RecommendationSet(
            'complementary',
            'Often needed with this',
            'Chosen by the marketplace team for this kind of product. It is an editorial suggestion, not what other shoppers did.',
            $items,
            editorial: true,
        );
    }

    public function sameCategoryAndBrand(Product $product, ?Actor $actor = null, int $limit = 6): ?RecommendationSet
    {
        if ($product->category_id === null) {
            return null;
        }

        $brand = DB::table('search_documents')->where('product_id', $product->id)->value('brand_n');
        $category = $this->categories->find((int) $product->category_id);

        $ids = DB::table('products as p')->leftJoin('search_documents as d', 'd.product_id', '=', 'p.id')
            ->where('p.category_id', $product->category_id)->where('p.id', '!=', $product->id)
            ->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.status', 'approved')->where('p.stock', '>', 0)
            ->orderByRaw('CASE WHEN d.brand_n = ? THEN 0 ELSE 1 END', [(string) $brand])
            ->orderByRaw('CASE WHEN p.rating_count >= ? THEN p.rating_average ELSE 0 END DESC', [(int) config('discovery.search.rating_min_reviews', 3)])
            ->orderByDesc('d.sold_count')->orderByDesc('p.id')
            ->limit($limit * 3)->get(['p.id', 'd.brand_n'])->map(fn ($r) => ['id' => (int) $r->id, 'same_brand' => $brand !== null && $r->brand_n === $brand]);

        $eligible = $this->eligible($ids->pluck('id')->all(), $actor ?? Actor::none(), $product->id);
        $items = [];

        foreach ($ids as $row) {
            if ($eligible->has($row['id'])) {
                $name = $category['name'] ?? 'this category';
                $items[] = new RecommendedItem($eligible[$row['id']], $row['same_brand'] ? "Same brand and category ({$name})" : "Same category ({$name})", null);
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items === [] ? null : new RecommendationSet(
            'same_category',
            'More in '.($category['name'] ?? 'this category'),
            'Other listings in the same category, same brand first. Nothing here is based on what shoppers did.',
            $items,
        );
    }

    public function recentlyViewed(Actor $actor, ?int $excluding = null, int $limit = 10): ?RecommendationSet
    {
        $products = $this->viewed->for($actor, $limit, $excluding);

        if ($products->isEmpty()) {
            return null;
        }

        return new RecommendationSet(
            'recently_viewed',
            'Recently viewed',
            'The products you looked at most recently, newest first. Only you can see this list.',
            $products->map(fn (Product $p) => new RecommendedItem($p, 'You viewed this'))->all(),
        );
    }

    /** Applies the product strategies to the last things the person looked at. */
    public function becauseYouViewed(Actor $actor, int $limit = 6): ?RecommendationSet
    {
        $ids = $this->viewed->viewedIds($actor, 3)->keys();

        return $this->becauseOf($actor, $ids, 'viewed', $limit);
    }

    /** Applies the product strategies to what the person bought. */
    public function becauseYouBought(Actor $actor, int $limit = 6): ?RecommendationSet
    {
        if ($actor->userId === null) {
            return null;
        }

        $ids = $this->counted->lines()->where('o.user_id', $actor->userId)->whereNotNull('oi.product_id')
            ->groupBy('oi.product_id')->selectRaw('oi.product_id as id, max(o.created_at) as at')->orderByDesc('at')->limit(3)->pluck('id');

        return $this->becauseOf($actor, $ids, 'bought', $limit);
    }

    /** Forget the cached links (after the builder has rewritten them). */
    public static function forgetCache(): void
    {
        try {
            Cache::put('discovery.rec.version', (int) Cache::get('discovery.rec.version', 0) + 1, 86400 * 30);
        } catch (Throwable) {
        }
    }

    /**
     * @param  Collection<int, int>  $anchorIds
     */
    private function becauseOf(Actor $actor, Collection $anchorIds, string $verb, int $limit): ?RecommendationSet
    {
        $anchors = Product::query()->withTrashed()->whereIn('id', $anchorIds)->get()->keyBy('id');

        foreach ($anchorIds as $id) {
            $anchor = $anchors->get($id);

            if ($anchor === null) {
                continue;
            }

            foreach ([
                $this->boughtTogether($anchor, $actor, $limit),
                $this->viewedTogether($anchor, $actor, $limit),
                $this->sameCategoryAndBrand($anchor, $actor, $limit),
            ] as $set) {
                if ($set !== null && ! $set->isEmpty()) {
                    return new RecommendationSet(
                        'because_you_'.$verb,
                        'Because you '.$verb.' '.$anchor->name,
                        'Based on your own history: '.lcfirst($set->basis),
                        array_map(fn (RecommendedItem $item) => new RecommendedItem($item->product, $item->basis.', because you '.$verb.' '.$anchor->name, $item->support), $set->items),
                    );
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, support: int}>
     */
    private function relations(int $productId, string $kind, int $min): array
    {
        $version = (int) Cache::get('discovery.rec.version', 0);
        $ttl = (int) config('discovery.recommendations.cache_seconds', 3600);

        $load = fn () => DB::table('product_relations')->where('product_id', $productId)->where('kind', $kind)->where('support', '>=', $min)
            ->orderByDesc('support')->orderBy('related_product_id')->limit((int) config('discovery.recommendations.per_product', 12))
            ->get(['related_product_id', 'support'])->map(fn ($r) => ['id' => (int) $r->related_product_id, 'support' => (int) $r->support])->all();

        try {
            return Cache::remember("discovery.rec.v{$version}.{$kind}.{$productId}.{$min}", $ttl, $load);
        } catch (Throwable) {
            return $load();
        }
    }

    /**
     * @param  list<array{id: int, support: int}>  $links
     * @return list<RecommendedItem>
     */
    private function items(array $links, ?Actor $actor, int $anchorId, int $limit, callable $basis): array
    {
        if ($links === []) {
            return [];
        }

        $eligible = $this->eligible(array_column($links, 'id'), $actor ?? Actor::none(), $anchorId);
        $items = [];

        foreach ($links as $link) {
            if ($eligible->has($link['id'])) {
                $items[] = new RecommendedItem($eligible[$link['id']], $basis($link['support']), $link['support']);
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Products a shopper could actually buy now, minus their own.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Product>
     */
    private function eligible(array $ids, Actor $actor, ?int $excluding): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Product::query()->browsable()->where('stock', '>', 0)
            ->whereIn('id', $ids)
            ->when($excluding, fn ($q) => $q->where('id', '!=', $excluding))
            ->when($actor->userId, fn ($q) => $q->whereNotIn('store_id', DB::table('stores')->where('owner_id', $actor->userId)->select('id')))
            ->with(['store', 'images'])->get()->keyBy('id');
    }

    /** @return Collection<int, int> */
    private function topInCategories(array $categoryIds, int $limit): Collection
    {
        return DB::table('products as p')->leftJoin('search_documents as d', 'd.product_id', '=', 'p.id')
            ->whereIn('p.category_id', $categoryIds)->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.status', 'approved')->where('p.stock', '>', 0)
            ->orderByRaw('CASE WHEN p.rating_count >= ? THEN p.rating_average ELSE 0 END DESC', [(int) config('discovery.search.rating_min_reviews', 3)])
            ->orderByDesc('d.sold_count')->orderByDesc('p.id')->limit($limit * 4)->pluck('p.id')->map(fn ($id) => (int) $id);
    }
}
