<?php

namespace App\Services\Storefront;

use App\Contracts\CategoryTree as CategoryLookup;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The public store directory. Public fields only: name, logo, place (region and city), description, what the platform knows about the
 * seller (InfoDot sign-in, a linked company, months here), the published rating and how much is for sale. No owner, e-mail, phone,
 * legal name, registration or VAT number, and nothing about money.
 */
class StoreDirectory
{
    public const SORTS = ['name' => 'Name', 'rating' => 'Best rated', 'products' => 'Most products', 'newest' => 'Newest'];

    public function __construct(private readonly CategoryLookup $categories) {}

    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        $region = trim((string) $request->query('region', ''));
        $category = $this->categoryFrom((string) $request->query('category', ''));
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'name';
        $min = (int) config('discovery.reviews.store_rating_min_reviews', 5);

        $browsable = Product::query()->browsable()->select('products.id');

        $stores = Store::query()->where('stores.is_active', true)
            ->leftJoin('store_rating_aggregates as r', 'r.store_id', '=', 'stores.id')
            ->select('stores.*')
            ->selectSub(
                Product::query()->browsable()->whereColumn('products.store_id', 'stores.id')->selectRaw('count(*)'),
                'live_products',
            )
            ->selectRaw('COALESCE(r.review_count, 0) as rated_count, case when COALESCE(r.review_count, 0) >= ? then r.average_rating else null end as shown_rating', [$min])
            ->with(['owner:id,infodot_signed_in_at', 'team:id,infodot_team_uuid,infodot_verified_at'])
            ->when($q !== '', function ($query) use ($q) {
                $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(fn ($w) => $w->whereRaw("stores.name {$operator} ? escape '\\'", [$like])->orWhereRaw("stores.description {$operator} ? escape '\\'", [$like]));
            })
            ->when($region !== '', fn ($query) => $query->where('stores.region', $region))
            ->when($category !== null, fn ($query) => $query->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('products as pc')
                ->whereColumn('pc.store_id', 'stores.id')->whereNull('pc.deleted_at')->where('pc.is_active', true)->where('pc.status', 'approved')
                ->whereIn('pc.category_id', $this->categories->withDescendants($category['id']))))
            // A store with nothing for sale is not worth a visit: it stays out of the directory until it lists something.
            ->whereExists(fn ($sub) => $sub->select(DB::raw(1))->from('products as pl')->whereColumn('pl.store_id', 'stores.id')->whereNull('pl.deleted_at')->where('pl.is_active', true)->where('pl.status', 'approved'));

        match ($sort) {
            'rating' => $stores->orderByRaw('case when COALESCE(r.review_count, 0) >= ? then 0 else 1 end', [$min])->orderByDesc('r.average_rating')->orderBy('stores.name'),
            'products' => $stores->orderByDesc('live_products')->orderBy('stores.name'),
            'newest' => $stores->orderByDesc('stores.id'),
            default => $stores->orderBy('stores.name'),
        };

        /** @var LengthAwarePaginator $page */
        $page = $stores->paginate(12)->withQueryString();

        return [
            'stores' => $page,
            'q' => $q,
            'region' => $region,
            'category' => $category,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'regions' => Store::query()->where('is_active', true)->whereNotNull('region')->where('region', '!=', '')->distinct()->orderBy('region')->pluck('region')->all(),
            'categoryOptions' => $this->categoryOptions(),
        ];
    }

    /** Public snippets of a store row from index(): what the platform really knows. @return list<array{icon: string, text: string}> */
    public function facts(Store $store): array
    {
        $facts = [];

        if ($store->owner?->infodot_signed_in_at !== null) {
            $facts[] = ['icon' => 'shield-check', 'text' => 'Signs in through InfoDot'];
        }

        if ($store->team?->infodot_team_uuid) {
            $facts[] = ['icon' => 'building', 'text' => 'InfoDot company linked'];
        }

        $months = (int) $store->created_at?->diffInMonths(now());
        $facts[] = ['icon' => 'calendar', 'text' => $months < 1 ? 'New on Emall' : "On Emall for {$months} ".($months === 1 ? 'month' : 'months')];

        return $facts;
    }

    /**
     * The trust ladder as ds.seller-trust rungs: what the platform recorded (checked), what the seller typed (claimed), what is measured on
     * Emall with its sample (metric), and what is absent (missing). A measure below its minimum sample says so instead of showing a number.
     *
     * @param  array<string, mixed>  $trust  StoreTrust::for()
     * @return list<array{label: string, how: string, state: string, value: ?string}>
     */
    public function rungs(array $trust): array
    {
        $rungs = [];

        foreach ($trust['ladder'] as $rung) {
            $rungs[] = [
                'label' => $rung['label'],
                'how' => $rung['state'] === 'no' ? 'Not recorded' : ($rung['detail'] ?? 'Recorded by '.$rung['source']),
                'state' => ['yes' => 'checked', 'claimed' => 'claimed', 'no' => 'missing'][$rung['state']] ?? 'missing',
                'value' => null,
            ];
        }

        $m = $trust['metrics'];
        $number = fn (?float $v, string $unit) => $v === null ? 'Not enough orders yet' : rtrim(rtrim(number_format($v, 1), '0'), '.').($unit === 'percent' ? '%' : ' hours');

        $rungs[] = ['label' => 'Shipped within the promised time', 'how' => $m['fulfilment_rate']['note'] ?? '', 'state' => 'metric', 'value' => $number($m['fulfilment_rate']['value'], 'percent')];
        $rungs[] = ['label' => 'Typical time from payment to shipping', 'how' => $m['median_hours_to_ship']['note'] ?? '', 'state' => 'metric', 'value' => $number($m['median_hours_to_ship']['value'], 'hours')];
        $rungs[] = ['label' => 'Items with a return requested', 'how' => $m['return_rate']['note'] ?? '', 'state' => 'metric', 'value' => $number($m['return_rate']['value'], 'percent')];
        $rungs[] = [
            'label' => 'Store rating',
            'how' => $m['review_average']['note'] ?? '',
            'state' => 'metric',
            'value' => $m['review_average']['value'] === null ? ($m['review_count']['value'] > 0 ? 'Not enough reviews yet' : 'No reviews yet') : number_format((float) $m['review_average']['value'], 1).' out of 5',
        ];

        return $rungs;
    }

    public function logoUrl(Store $store): ?string
    {
        return $store->logo_path ? Storage::disk('public')->url($store->logo_path) : null;
    }

    public function bannerUrl(Store $store): ?string
    {
        return $store->banner_path ? Storage::disk('public')->url($store->banner_path) : null;
    }

    /** @return array<string, mixed>|null */
    private function categoryFrom(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        return ctype_digit($value) ? $this->categories->find((int) $value) : $this->categories->findBySlug($value);
    }

    /**
     * Top-level categories that have something for sale in any store.
     *
     * @return list<array{slug: string, name: string}>
     */
    private function categoryOptions(): array
    {
        $roots = collect($this->categories->all())->filter(fn ($c) => ($c['parent_id'] ?? null) === null && ($c['is_active'] ?? true));

        if ($roots->isEmpty()) {
            return [];
        }

        $used = Product::query()->browsable()->whereNotNull('category_id')->distinct()->pluck('category_id')->map(fn ($id) => (int) $id)->all();

        return $roots->filter(function ($root) use ($used) {
            return array_intersect($this->categories->withDescendants($root['id']), $used) !== [];
        })->map(fn ($c) => ['slug' => $c['slug'], 'name' => $c['name']])->sortBy('name')->values()->all();
    }
}
