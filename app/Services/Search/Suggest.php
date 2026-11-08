<?php

namespace App\Services\Search;

use App\Services\Discovery\SearchLog;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

/**
 * Autocomplete. Everything offered is real: listings that exist and are buyable, categories and brands that have
 * listings (with their counts), the person's own recent searches, and searches enough different people ran that
 * found something. No "trending" label unless the log can back it.
 */
class Suggest
{
    public function __construct(
        private readonly SearchEngine $engine,
        private readonly SearchLog $log,
    ) {}

    /**
     * @return array{query: string, products: list<array<string, mixed>>, categories: list<array<string, mixed>>, brands: list<array<string, mixed>>, recent: list<string>, popular: list<array{query: string, people: int}>}
     */
    public function for(string $typed, ?Actor $actor = null): array
    {
        $typed = trim(mb_substr($typed, 0, 60));
        $normalised = Text::normalise($typed);
        $actor ??= Actor::none();

        $out = ['query' => $typed, 'products' => [], 'categories' => [], 'brands' => [], 'recent' => [], 'popular' => []];

        $out['recent'] = $this->log->recentFor($actor, (int) config('discovery.suggest.recent', 5), $normalised)->values()->all();

        if (strlen($normalised) < 2) {
            // Nothing typed yet: the person's own history and what is popular.
            $out['popular'] = $this->log->popular((int) config('discovery.suggest.popular', 5))->values()->all();

            return $out;
        }

        $out['products'] = $this->engine->suggestProducts($typed, (int) config('discovery.suggest.products', 6));
        $out['categories'] = $this->categories($normalised);
        $out['brands'] = $this->brands($normalised);
        $out['popular'] = $this->log->popular((int) config('discovery.suggest.popular', 5), $normalised)->values()->all();

        return $out;
    }

    /** @return list<array{id: int, name: string, slug: string, count: int}> */
    private function categories(string $normalised): array
    {
        $words = Text::tokens($normalised);

        if ($words === []) {
            return [];
        }

        $terms = DB::table('search_terms')->where('kind', 'category');

        foreach ($words as $word) {
            $terms->whereRaw("(' ' || term) LIKE ? ESCAPE '\\'", ['% '.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%']);
        }

        $ids = $terms->limit((int) config('discovery.suggest.categories', 3))->pluck('ref_id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $counts = DB::table('products as p')->join('stores as s', 's.id', '=', 'p.store_id')
            ->whereIn('p.category_id', $ids)->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.status', 'approved')->where('s.is_active', true)
            ->groupBy('p.category_id')->selectRaw('p.category_id as id, count(*) as c')->pluck('c', 'id');

        $rows = DB::table('store_categories')->whereIn('id', $ids)->get(['id', 'name', 'slug']);

        return $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'count' => (int) ($counts[$r->id] ?? 0)])
            ->filter(fn ($row) => $row['count'] > 0)->values()->all();
    }

    /** @return list<array{name: string, count: int}> */
    private function brands(string $normalised): array
    {
        $words = Text::tokens($normalised);

        if ($words === []) {
            return [];
        }

        $terms = DB::table('search_terms')->where('kind', 'brand');

        foreach ($words as $word) {
            $terms->whereRaw("(' ' || term) LIKE ? ESCAPE '\\'", ['% '.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%']);
        }

        $names = $terms->limit((int) config('discovery.suggest.brands', 3))->pluck('term')->all();

        if ($names === []) {
            return [];
        }

        return DB::table('search_documents as d')->join('products as p', 'p.id', '=', 'd.product_id')->join('stores as s', 's.id', '=', 'p.store_id')
            ->whereIn('d.brand_n', $names)->whereNull('p.deleted_at')->where('p.is_active', true)->where('p.status', 'approved')->where('s.is_active', true)
            ->groupBy('d.brand_n')->selectRaw('max(d.brand) as name, count(*) as c')->orderByDesc('c')->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->c])->all();
    }
}
