<?php

namespace App\Services\Discovery;

use App\Models\Product;
use App\Services\Community\StoreRatings;
use App\Support\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The homepage as data: named sections, each with a stated basis, each built from real rows and OMITTED when it has
 * nothing honest to show. There is no placeholder content, no invented popularity and no paid placement.
 *
 *   continue        your recently viewed products
 *   interests       products from categories you have viewed or bought from at least twice
 *   new_arrivals    buyable products listed in the last 30 days
 *   trending        products with at least 5 views plus units sold in the last 7 days, with the numbers
 *   categories      categories ranked by real views and units sold in the last 30 days (minimum 5)
 *   stores          stores with at least 3 counted orders in the last 90 days, with their rating when it is shown
 *   collections     operator collections that are live and non-empty
 *   searches        searches at least 3 different people ran in the last 30 days that found something
 *   local           stores whose region matches the visitor's, when the region is known
 *   reorder         products you bought before that can still be bought
 *
 * Each call computes its sections from the database (about a dozen small queries); a caller that renders it
 * for anonymous visitors can cache the result per region.
 */
class Homepage
{
    public function __construct(
        private readonly RecentlyViewed $viewed,
        private readonly CountedOrders $counted,
        private readonly Collections $collections,
        private readonly SearchLog $searches,
        private readonly StoreRatings $ratings,
    ) {}

    /**
     * @return list<HomeSection>
     */
    public function for(Actor $actor, ?string $region = null): array
    {
        $sections = [
            $this->continueWhereYouLeftOff($actor),
            $this->interests($actor),
            $this->newArrivals(),
            $this->trending(),
            $this->popularCategories(),
            $this->featuredStores(),
            $this->collectionsSection(),
            $this->popularSearches(),
            $region !== null && $region !== '' ? $this->localSellers($region) : null,
            $this->reorder($actor),
        ];

        return array_values(array_filter($sections, fn (?HomeSection $section) => $section !== null && $section->items !== []));
    }

    private function continueWhereYouLeftOff(Actor $actor): ?HomeSection
    {
        $products = $this->viewed->for($actor, $this->size());

        if ($products->isEmpty()) {
            return null;
        }

        return new HomeSection(
            'continue',
            'Continue where you left off',
            'The '.$products->count().' '.($products->count() === 1 ? 'product' : 'products').' you looked at most recently. Only you see this.',
            'products',
            $products->map(fn (Product $p) => new RecommendedItem($p, 'You viewed this'))->all(),
            ['views' => $products->count()],
            personal: true,
        );
    }

    private function interests(Actor $actor): ?HomeSection
    {
        $viewed = $this->viewed->viewedIds($actor, 20)->keys()->all();
        $bought = $actor->userId !== null
            ? $this->counted->lines()->where('o.user_id', $actor->userId)->whereNotNull('oi.product_id')->distinct()->pluck('oi.product_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $evidenceIds = array_values(array_unique(array_merge($viewed, $bought)));

        if ($evidenceIds === []) {
            return null;
        }

        $counts = DB::table('products')->whereIn('id', $evidenceIds)->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, count(*) as n')->havingRaw('count(*) >= 2')->orderByDesc('n')->limit(2)->pluck('n', 'category_id');

        if ($counts->isEmpty()) {
            return null;
        }

        $products = Product::query()->browsable()->where('stock', '>', 0)
            ->whereIn('category_id', $counts->keys())->whereNotIn('id', $evidenceIds)
            ->when($actor->userId, fn ($q) => $q->whereNotIn('store_id', DB::table('stores')->where('owner_id', $actor->userId)->select('id')))
            ->with(['store', 'images'])->orderByDesc('rating_count')->orderByDesc('id')->limit($this->size())->get();

        if ($products->isEmpty()) {
            return null;
        }

        $names = DB::table('store_categories')->whereIn('id', $counts->keys())->pluck('name', 'id');

        return new HomeSection(
            'interests',
            'Picked from your interests',
            'From categories you have looked at or bought from at least twice: '.$counts->map(fn ($n, $id) => ($names[$id] ?? 'a category')." ({$n} times)")->implode(', ').'. Products you have already seen are left out.',
            'products',
            $products->map(fn (Product $p) => new RecommendedItem($p, 'In '.($names[$p->category_id] ?? 'a category you follow')))->all(),
            ['categories' => $counts->all()],
            personal: true,
        );
    }

    private function newArrivals(): ?HomeSection
    {
        $days = (int) config('discovery.homepage.new_arrival_days', 30);

        $products = Product::query()->browsable()->where('stock', '>', 0)->where('created_at', '>=', now()->subDays($days))
            ->with(['store', 'images'])->orderByDesc('created_at')->orderByDesc('id')->limit($this->size())->get();

        return $products->isEmpty() ? null : new HomeSection(
            'new_arrivals',
            'New arrivals',
            "Buyable products listed in the last {$days} days, newest first.",
            'products',
            $products->map(fn (Product $p) => new RecommendedItem($p, 'Listed '.$p->created_at->diffForHumans()))->all(),
            ['days' => $days],
        );
    }

    private function trending(): ?HomeSection
    {
        $days = (int) config('discovery.homepage.trending_window_days', 7);
        $min = (int) config('discovery.homepage.trending_min_events', 5);
        $since = now()->subDays($days);

        $views = DB::table('commerce_events')->where('name', Track::PRODUCT_VIEW)->where('created_at', '>=', $since)->whereNotNull('product_id')
            ->groupBy('product_id')->selectRaw('product_id, count(*) as n')->pluck('n', 'product_id')->map(fn ($n) => (int) $n)->all();
        $units = $this->counted->unitsSoldByProduct($since);

        $scores = [];

        foreach (array_unique(array_merge(array_keys($views), array_keys($units))) as $id) {
            $total = ($views[$id] ?? 0) + ($units[$id] ?? 0);

            if ($total >= $min) {
                $scores[$id] = $total;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $products = Product::query()->browsable()->where('stock', '>', 0)->whereIn('id', array_keys($scores))->with(['store', 'images'])->get()->keyBy('id');
        $items = [];

        foreach (array_keys($scores) as $id) {
            if ($product = $products->get($id)) {
                $v = $views[$id] ?? 0;
                $u = $units[$id] ?? 0;
                $items[] = new RecommendedItem($product, "{$v} ".($v === 1 ? 'view' : 'views')." and {$u} sold in the last {$days} days", $scores[$id]);
            }

            if (count($items) >= $this->size()) {
                break;
            }
        }

        return $items === [] ? null : new HomeSection('trending', 'Trending now', "Products with at least {$min} views plus units sold in the last {$days} days. The numbers are shown.", 'products', $items, ['days' => $days, 'minimum' => $min]);
    }

    private function popularCategories(): ?HomeSection
    {
        $min = (int) config('discovery.homepage.popular_category_min_events', 5);
        $since = now()->subDays(30);

        $views = DB::table('commerce_events as e')->join('products as p', 'p.id', '=', 'e.product_id')
            ->where('e.name', Track::PRODUCT_VIEW)->where('e.created_at', '>=', $since)->whereNotNull('p.category_id')
            ->groupBy('p.category_id')->selectRaw('p.category_id as id, count(*) as n')->pluck('n', 'id')->map(fn ($n) => (int) $n)->all();
        $units = $this->counted->lines($since)->join('products as p', 'p.id', '=', 'oi.product_id')->whereNotNull('p.category_id')
            ->groupBy('p.category_id')->selectRaw('p.category_id as id, sum(oi.quantity) as n')->pluck('n', 'id')->map(fn ($n) => (int) $n)->all();

        $scores = [];

        foreach (array_unique(array_merge(array_keys($views), array_keys($units))) as $id) {
            $total = ($views[$id] ?? 0) + ($units[$id] ?? 0);

            if ($total >= $min) {
                $scores[$id] = $total;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);
        $scores = array_slice($scores, 0, 8, true);
        $rows = DB::table('store_categories')->whereIn('id', array_keys($scores))->get(['id', 'name', 'slug'])->keyBy('id');
        $listings = DB::table('products as p')->join('stores as s', 's.id', '=', 'p.store_id')->whereIn('p.category_id', array_keys($scores))
            ->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.status', 'approved')->where('s.is_active', true)
            ->groupBy('p.category_id')->selectRaw('p.category_id as id, count(*) as n')->pluck('n', 'id');

        $items = [];

        foreach ($scores as $id => $total) {
            if (isset($rows[$id]) && ($listings[$id] ?? 0) > 0) {
                $items[] = [
                    'id' => (int) $id, 'name' => $rows[$id]->name, 'slug' => $rows[$id]->slug, 'listings' => (int) $listings[$id],
                    'basis' => ($views[$id] ?? 0).' views and '.($units[$id] ?? 0).' units sold in the last 30 days',
                ];
            }
        }

        return $items === [] ? null : new HomeSection('categories', 'Popular categories', "Categories ranked by real views and units sold in the last 30 days, shown from {$min}.", 'categories', $items, ['minimum' => $min]);
    }

    private function featuredStores(): ?HomeSection
    {
        $min = (int) config('discovery.homepage.featured_store_min_orders', 3);
        $days = (int) config('discovery.homepage.featured_store_window_days', 90);

        $rows = $this->counted->lines(now()->subDays($days))->whereNotNull('oi.store_id')
            ->groupBy('oi.store_id')->selectRaw('oi.store_id as id, count(distinct oi.order_id) as orders')
            ->havingRaw('count(distinct oi.order_id) >= ?', [$min])->orderByDesc('orders')->orderBy('id')->limit(6)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $stores = DB::table('stores')->whereIn('id', $rows->pluck('id'))->where('is_active', true)->get(['id', 'name', 'slug', 'logo_path'])->keyBy('id');
        $items = [];
        $ratings = $this->ratings->summaries($stores->keys());

        foreach ($rows as $row) {
            if (! isset($stores[$row->id])) {
                continue;
            }

            $rating = $ratings[(int) $row->id];
            $basis = ((int) $row->orders)." orders in the last {$days} days";

            if ($rating['shown']) {
                $basis .= ', rated '.number_format((float) $rating['average'], 1).' from '.$rating['count'].' reviews';
            }

            $items[] = ['id' => (int) $row->id, 'name' => $stores[$row->id]->name, 'slug' => $stores[$row->id]->slug, 'logo_path' => $stores[$row->id]->logo_path, 'orders' => (int) $row->orders, 'rating' => $rating['average'], 'basis' => $basis];
        }

        return $items === [] ? null : new HomeSection('stores', 'Stores with a track record', "Stores with at least {$min} completed orders in the last {$days} days. Ranked by orders, never by payment.", 'stores', $items, ['minimum' => $min, 'days' => $days]);
    }

    private function collectionsSection(): ?HomeSection
    {
        try {
            $live = $this->collections->live(4, 4);
        } catch (Throwable) {
            return null;
        }

        if ($live->isEmpty()) {
            return null;
        }

        return new HomeSection(
            'collections',
            'Collections',
            'Hand-picked or rule-based groups made by the marketplace team. Only buyable products are shown.',
            'collections',
            $live->map(fn ($row) => ['collection' => $row['collection'], 'preview' => $row['preview'], 'basis' => $row['collection']->type === 'manual' ? 'Picked by our team' : 'Always up to date with its rule'])->all(),
        );
    }

    private function popularSearches(): ?HomeSection
    {
        $min = (int) config('discovery.suggest.popular_min_people', 3);
        $popular = $this->searches->popular(8);

        return $popular->isEmpty() ? null : new HomeSection(
            'searches',
            'Popular searches',
            "Searches at least {$min} different people ran in the last ".config('discovery.suggest.popular_window_days', 30).' days that found something.',
            'searches',
            $popular->map(fn ($row) => ['query' => $row['query'], 'people' => $row['people'], 'basis' => $row['people'].' people searched for this'])->all(),
            ['minimum' => $min],
        );
    }

    private function localSellers(string $region): ?HomeSection
    {
        $rows = DB::table('search_documents as d')->join('products as p', 'p.id', '=', 'd.product_id')->join('stores as s', 's.id', '=', 'p.store_id')
            ->where('d.region', $region)->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.status', 'approved')->where('s.is_active', true)
            ->groupBy('s.id', 's.name', 's.slug')->selectRaw('s.id as id, s.name as name, s.slug as slug, count(*) as listings')
            ->orderByDesc('listings')->orderBy('s.id')->limit(6)->get();

        return $rows->isEmpty() ? null : new HomeSection(
            'local',
            'Sellers in '.$region,
            'Stores that sell from '.$region.', by the region they gave. It is not a guarantee of faster delivery.',
            'stores',
            $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'listings' => (int) $r->listings, 'basis' => $r->listings.' '.($r->listings == 1 ? 'listing' : 'listings')])->all(),
            ['region' => $region],
        );
    }

    private function reorder(Actor $actor): ?HomeSection
    {
        if ($actor->userId === null) {
            return null;
        }

        $rows = $this->counted->lines()->where('o.user_id', $actor->userId)->whereNotNull('oi.product_id')
            ->groupBy('oi.product_id')->selectRaw('oi.product_id as id, max(o.created_at) as at, count(distinct o.id) as times')
            ->orderByDesc('at')->limit($this->size() * 2)->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $products = Product::query()->browsable()->whereIn('id', $rows->pluck('id'))
            ->whereNotIn('store_id', DB::table('stores')->where('owner_id', $actor->userId)->select('id'))
            ->with(['store', 'images'])->get()->keyBy('id');
        $items = [];

        foreach ($rows as $row) {
            if ($product = $products->get($row->id)) {
                $when = Carbon::parse($row->at)->format('j M Y');
                $items[] = new RecommendedItem($product, 'You bought this on '.$when.((int) $row->times > 1 ? ' ('.$row->times.' times)' : ''), (int) $row->times);
            }

            if (count($items) >= $this->size()) {
                break;
            }
        }

        return $items === [] ? null : new HomeSection('reorder', 'Buy again', 'Products from your own paid orders that can still be bought. Check the price before you order: it may have changed.', 'products', $items, [], personal: true);
    }

    private function size(): int
    {
        return (int) config('discovery.homepage.section_size', 8);
    }
}
