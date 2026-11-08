<?php

namespace App\Http\Controllers\Discovery;

use App\Contracts\CatalogueSource;
use App\Http\Controllers\Controller;
use App\Models\StoreCategory;
use App\Services\Catalogue\CategoryTree;
use App\Services\Discovery\Recommender;
use App\Services\Discovery\SearchLog;
use App\Services\Search\SearchRequest;
use App\Services\Search\SearchResult;
use App\Services\Search\SearchService;
use App\Support\Actor;
use App\Support\SearchLinks;
use App\Support\ShopperRegion;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * One search experience under three addresses: /search, /products (the shop's front door, same page) and
 * /categories/{slug} (the same page fixed to a category). Everything is a GET and everything is in the query string:
 * filters, sort, page and view, so a result is a link and works with no script.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $search,
        private readonly CategoryTree $tree,
    ) {}

    public function index(Request $request): View
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // A category filter must be a real category, by id or by slug (a bad one is a validation error, as it always was).
            'category' => ['nullable', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail): void {
                $known = ctype_digit((string) $value) ? $this->tree->find((int) $value) : $this->tree->findBySlug((string) $value);

                if ($known === null) {
                    $fail('That category does not exist.');
                }
            }],
        ]);

        return $this->render($request, route('search'), null);
    }

    /** /categories/{category}: the slug of an active category. */
    public function category(Request $request, string $category): View
    {
        $row = $this->tree->findBySlug($category);

        abort_if($row === null || ! $row['is_active'], 404);

        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return $this->render($request, route('categories.show', $row['slug']), $row);
    }

    /**
     * @param  array<string, mixed>|null  $category  the fixed category of a category page
     */
    private function render(Request $request, string $base, ?array $category): View
    {
        $input = $this->cleaned($request->query());
        $search = SearchRequest::fromArray($input);

        if ($category !== null) {
            $search->categoryId = $category['id'];
        }

        $view = in_array($input['view'] ?? null, ['grid', 'list'], true) ? $input['view'] : 'auto';
        $literal = ! $search->understand;
        $links = new SearchLinks($base, $search, $view === 'list' ? 'list' : 'grid', $literal, $category !== null ? ['category' => $category['id']] : []);

        $actor = Actor::forRequest($request);
        $result = $this->search->search($search, $actor);

        $products = new LengthAwarePaginator(
            $result->items,
            $result->total,
            $result->perPage,
            $result->page,
            ['path' => $base, 'query' => $links->carried()],
        );

        $mine = ShopperRegion::current($request);
        $storeIds = array_values(array_unique(array_map(fn ($hit) => (int) $hit->product->store_id, $result->items)));
        $regions = $storeIds !== [] ? app(CatalogueSource::class)->storeRegions($storeIds) : [];
        $active = $search->filters();

        if ($category !== null) {
            unset($active['category']);
        }

        $filtered = $active !== [];

        return view('products.index', [
            'search' => $search,
            'result' => $result,
            'products' => $products,
            'links' => $links,
            'view' => $view,
            'category' => $category,
            'categoryNode' => $category !== null ? $this->node($category['id']) : null,
            'categoryDescription' => $category !== null ? StoreCategory::query()->whereKey($category['id'])->value('description') : null,
            'breadcrumbs' => $category !== null ? $this->breadcrumbs($category['id']) : [],
            'chips' => $this->chips($result, $links, $category !== null),
            'facetCategories' => $category === null && ! $this->categoryInferred($result) ? $this->categoryOptions($result) : [],
            'sorts' => $this->sorts($search),
            'mine' => $mine,
            'regions' => $regions,
            'localCount' => $this->localCount($result, $mine, $search),
            'topBrands' => $category !== null ? $this->topBrands($result, $links) : [],
            'zero' => $result->isEmpty() ? $this->zeroResults($actor) : null,
            'keepRobots' => ! ($search->text !== '' || $filtered || $search->sort !== 'relevance' || $search->page > 1 || $view !== 'auto' || $literal),
            'signedIn' => $request->user() !== null,
            'canSave' => $search->text !== '' || $filtered,
        ]);
    }

    /**
     * Only scalars and lists of scalars from the query string get further: a nested array where a string belongs is dropped.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function cleaned(array $query): array
    {
        $scalar = fn ($v) => is_scalar($v);
        $out = [];

        foreach (['q', 'category', 'price_min', 'price_max', 'in_stock', 'rating', 'sort', 'page', 'view', 'literal'] as $key) {
            if (isset($query[$key]) && $scalar($query[$key])) {
                $out[$key] = $query[$key];
            }
        }

        foreach (['brand', 'store', 'region'] as $key) {
            if (isset($query[$key])) {
                $out[$key] = array_values(array_filter((array) $query[$key], $scalar));
            }
        }

        foreach ((array) ($query['attr'] ?? []) as $key => $values) {
            if (is_string($key)) {
                $out['attr'][$key] = array_values(array_filter((array) $values, $scalar));
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function node(int $id): ?array
    {
        $find = function (array $nodes) use (&$find, $id): ?array {
            foreach ($nodes as $node) {
                if ($node['id'] === $id) {
                    return $node;
                }

                if ($found = $find($node['children'])) {
                    return $found;
                }
            }

            return null;
        };

        return $find($this->tree->tree());
    }

    /** @return list<array{label: string, href: ?string}> */
    private function breadcrumbs(int $id): array
    {
        $category = StoreCategory::query()->find($id);
        $trail = [['label' => 'Home', 'href' => url('/')], ['label' => 'Categories', 'href' => route('categories.index')]];

        foreach ($category ? $this->tree->breadcrumbs($category) : [] as $step) {
            $trail[] = ['label' => $step->name, 'href' => $step->id === $id ? null : route('categories.show', $step->slug)];
        }

        return $trail;
    }

    /**
     * @return list<array{key: string, label: string, remove: string, inferred: bool}>
     */
    private function chips(SearchResult $result, SearchLinks $links, bool $onCategoryPage): array
    {
        $chips = [];

        foreach ($result->appliedFilters as $chip) {
            if ($onCategoryPage && $chip['key'] === 'category' && ! $chip['inferred']) {
                continue;
            }

            $chips[] = [
                'key' => $chip['key'],
                'label' => $chip['label'],
                'remove' => $chip['inferred'] ? $links->wordsOnly() : $links->without($chip['key']),
                'inferred' => (bool) $chip['inferred'],
            ];
        }

        return $chips;
    }

    /** When the words themselves named the category ("laptop"), the category facet would list every other category with counts that ignore the words, so it is left out until the chip is removed. */
    private function categoryInferred(SearchResult $result): bool
    {
        foreach ($result->appliedFilters as $chip) {
            if ($chip['key'] === 'category' && $chip['inferred']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The category facet as radio options. Two categories with the same name ("Clothing" under men and under women) are told apart by their parent.
     *
     * @return list<array{value: int, label: string, count: int, checked: bool}>
     */
    private function categoryOptions(SearchResult $result): array
    {
        $rows = $result->facets['categories'] ?? [];
        $names = array_count_values(array_map(fn ($r) => $r['name'], $rows));

        return array_map(function (array $row) use ($names) {
            $path = $this->tree->path($row['id']);
            $label = ($names[$row['name']] ?? 0) > 1 && count($path) > 1 ? $row['name'].' ('.$path[count($path) - 2].')' : $row['name'];

            return ['value' => $row['id'], 'label' => $label, 'count' => $row['count'], 'checked' => (bool) $row['selected']];
        }, $rows);
    }

    /** @return array<string, string> */
    private function sorts(SearchRequest $search): array
    {
        return [
            'relevance' => $search->text !== '' ? 'Best match' : 'Recommended order',
            'price_asc' => 'Price: low to high',
            'price_desc' => 'Price: high to low',
            'newest' => 'Newest',
            'rating' => 'Top rated',
            'best_selling' => 'Best selling',
        ];
    }

    /** How many results are from sellers in the shopper's province, when that is a useful extra filter. @return array{region: string, count: int}|null */
    private function localCount(SearchResult $result, ?string $mine, SearchRequest $search): ?array
    {
        if ($mine === null || in_array($mine, $search->regions, true)) {
            return null;
        }

        foreach ($result->facets['regions'] ?? [] as $row) {
            if ($row['value'] === $mine && $row['count'] > 0) {
                return ['region' => $mine, 'count' => (int) $row['count']];
            }
        }

        return null;
    }

    /** @return list<array{label: string, count: int, href: string}> */
    private function topBrands(SearchResult $result, SearchLinks $links): array
    {
        $out = [];

        foreach (array_slice($result->facets['brands'] ?? [], 0, 8) as $brand) {
            $out[] = ['label' => $brand['label'], 'count' => (int) $brand['count'], 'selected' => (bool) $brand['selected'], 'href' => $links->with(['brands' => [$brand['value']]])];
        }

        return $out;
    }

    /** What an empty page offers instead: real popular searches, real categories, the person's own recent views. @return array<string, mixed> */
    private function zeroResults(Actor $actor): array
    {
        $categories = array_values(array_filter($this->tree->tree(), fn (array $root) => $root['rollup_count'] > 0));
        usort($categories, fn ($a, $b) => $b['rollup_count'] <=> $a['rollup_count']);

        return [
            'popular' => app(SearchLog::class)->popular(6)->values()->all(),
            'categories' => array_slice($categories, 0, 8),
            'viewed' => app(Recommender::class)->recentlyViewed($actor, null, 6),
        ];
    }
}
