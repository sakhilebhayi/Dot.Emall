<?php

namespace App\Http\Controllers\Discovery;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\Catalogue\CategoryTree;
use App\Services\Discovery\HomeSection;
use App\Services\Discovery\Homepage;
use App\Services\Discovery\RecommendedItem;
use App\Support\Actor;
use App\Support\ShopperRegion;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The home page for everyone. Its sections come from Discovery\Homepage (each states its basis and is absent when it has nothing
 * honest to show); this adds the category shelf (real counts, a real photograph of something in the category) and the featured product.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, Homepage $homepage, CategoryTree $tree): View
    {
        $region = ShopperRegion::current($request);

        // The front door must open even when the catalogue cannot be read: the data blocks are then simply absent.
        try {
            $sections = collect($homepage->for(Actor::forRequest($request), $region))->keyBy(fn (HomeSection $s) => $s->key);

            $stores = collect();
            foreach (['stores', 'local'] as $key) {
                if ($section = $sections->get($key)) {
                    $stores = $stores->merge(collect($section->items)->pluck('id'));
                }
            }
            $storeModels = $stores->isEmpty() ? collect() : Store::query()->whereIn('id', $stores->unique())->get()->keyBy('id');
            $shelf = $this->categoryShelf($tree);
        } catch (Throwable $e) {
            report($e);
            $sections = collect();
            $storeModels = collect();
            $shelf = [];
        }

        return view('welcome', [
            'sections' => $sections,
            'region' => $region,
            'shelf' => $shelf,
            'feature' => $this->feature($sections),
            'storeModels' => $storeModels,
            'signedIn' => $request->user() !== null,
        ]);
    }

    /**
     * The category tiles: the top-level categories a shopper can buy from, biggest first, each with its real count and the photograph of a well-reviewed
     * product in it (one query for all tiles). Ordered by how many products can be bought, which is stated on the page.
     *
     * @return list<array{id: int, name: string, slug: string, count: int, image: ?string, basis: string}>
     */
    private function categoryShelf(CategoryTree $tree): array
    {
        $roots = array_values(array_filter($tree->tree(), fn (array $r) => $r['rollup_count'] > 0));

        if ($roots === []) {
            return [];
        }

        usort($roots, fn ($a, $b) => $b['rollup_count'] <=> $a['rollup_count']);
        // A lead tile (two by two) plus rows of four leaves no gap at 5 or 9 tiles; fewer than 5 are drawn equal.
        $roots = array_slice($roots, 0, count($roots) >= 9 ? 9 : min(5, count($roots)));

        $byCategory = [];
        $rootOf = [];
        foreach ($roots as $root) {
            foreach ($tree->withDescendants($root['id']) as $id) {
                $rootOf[$id] = $root['id'];
            }
        }

        Product::query()->browsable()->whereIn('category_id', array_keys($rootOf))->where('stock', '>', 0)->whereHas('images')
            ->with('images')->orderByDesc('rating_count')->orderByDesc('id')->limit(120)->get()
            ->each(function (Product $p) use (&$byCategory, $rootOf) {
                $byCategory[$rootOf[$p->category_id]] ??= $p->images->first()->url();
            });

        return array_map(fn (array $r) => [
            'id' => $r['id'],
            'name' => $r['name'],
            'slug' => $r['slug'],
            'count' => $r['rollup_count'],
            'image' => $byCategory[$r['id']] ?? null,
            'basis' => number_format($r['rollup_count']).' '.($r['rollup_count'] === 1 ? 'product' : 'products').' you can buy today',
        ], $roots);
    }

    /** The product shown with its label beside the headline: trending first, else the newest arrival, with the reason. @return array{product: Product, basis: string}|null */
    private function feature($sections): ?array
    {
        foreach (['trending', 'new_arrivals', 'interests', 'continue'] as $key) {
            $section = $sections->get($key);

            foreach ($section?->items ?? [] as $item) {
                /** @var RecommendedItem $item */
                if ($item->product->images->isNotEmpty()) {
                    return ['product' => $item->product, 'basis' => ($key === 'trending' ? 'Trending: ' : ($key === 'new_arrivals' ? 'New: ' : '')).$item->basis];
                }
            }
        }

        return null;
    }
}
