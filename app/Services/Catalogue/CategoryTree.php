<?php

namespace App\Services\Catalogue;

use App\Contracts\CategoryTree as CategoryLookup;
use App\Models\Product;
use App\Models\StoreCategory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The category tree: creating and moving nodes, breadcrumbs, and rollup counts of LISTED products.
 *
 * "Listed" means what a buyer can see: Product::browsable() (active, approved, store open, not archived). A parent's
 * rollup count includes every descendant. Counts come from ONE grouped query, rolled up in memory, and are cached
 * for a minute; any product change that can alter them calls forgetCounts().
 */
class CategoryTree implements CategoryLookup
{
    private const CACHE_KEY = 'emall.category_counts.v1';

    /** The categories of this request by id, read once (a marketplace has hundreds of categories, not millions). */
    private ?array $all = null;

    /**
     * @param  array{name: string, slug?: ?string, sort_order?: int, description?: ?string, image_path?: ?string, is_active?: bool}  $data
     */
    public function create(array $data, ?StoreCategory $parent = null): StoreCategory
    {
        $slug = $data['slug'] ?? Str::slug($data['name']);

        if (StoreCategory::query()->where('slug', $slug)->exists()) {
            $slug = $this->uniqueSlug($slug);
        }

        $category = StoreCategory::create(array_merge($data, ['slug' => $slug, 'parent_id' => $parent?->id]));
        self::forgetCounts();
        $this->forget();

        return $category->refresh();
    }

    /**
     * Re-parents a node (null = make it a root), rewriting the path and depth of it and everything beneath it.
     */
    public function move(StoreCategory $category, ?StoreCategory $newParent): StoreCategory
    {
        if ($newParent !== null && ($newParent->id === $category->id || str_starts_with((string) $newParent->path, (string) $category->path))) {
            throw new InvalidArgumentException('A category cannot be moved under itself.');
        }

        DB::transaction(function () use ($category, $newParent) {
            $oldPath = (string) $category->path;
            $newPath = ($newParent?->path ?? '/').$category->id.'/';
            $depthShift = ($newParent ? $newParent->depth + 1 : 0) - $category->depth;

            $category->forceFill(['parent_id' => $newParent?->id])->saveQuietly();

            StoreCategory::query()->where('path', 'like', $oldPath.'%')->get()->each(function (StoreCategory $node) use ($oldPath, $newPath, $depthShift) {
                $node->forceFill([
                    'path' => $newPath.substr((string) $node->path, strlen($oldPath)),
                    'depth' => $node->depth + $depthShift,
                ])->saveQuietly();
            });
        });

        self::forgetCounts();
        $this->forget();

        return $category->refresh();
    }

    /**
     * @return array<int, StoreCategory> root to the category itself
     */
    public function breadcrumbs(StoreCategory $category): array
    {
        $ids = array_values(array_filter(explode('/', (string) $category->path)));
        $nodes = StoreCategory::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $nodes->get((int) $id))->filter()->values()->all();
    }

    /**
     * The whole tree as nested arrays, ordered, with counts. Two queries however deep it is.
     *
     * @return array<int, array{id: int, name: string, slug: string, depth: int, direct_count: int, rollup_count: int, children: array}>
     */
    public function tree(bool $onlyActive = true): array
    {
        $categories = StoreCategory::query()
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $counts = $this->counts();

        $byParent = $categories->groupBy(fn ($c) => $c->parent_id ?? 0);

        $build = function (int $parentId) use (&$build, $byParent, $counts): array {
            return ($byParent->get($parentId) ?? collect())->map(fn (StoreCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'depth' => $c->depth,
                'image_path' => $c->image_path,
                'direct_count' => $counts[$c->id]['direct'] ?? 0,
                'rollup_count' => $counts[$c->id]['rollup'] ?? 0,
                'children' => $build($c->id),
            ])->values()->all();
        };

        return $build(0);
    }

    /**
     * @return array<int, array{direct: int, rollup: int}> keyed by category id (only categories with listed products or ancestors of them)
     */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $direct = Product::query()->browsable()
                ->whereNotNull('category_id')
                ->selectRaw('category_id, count(*) as aggregate')
                ->groupBy('category_id')
                ->pluck('aggregate', 'category_id');

            if ($direct->isEmpty()) {
                return [];
            }

            $paths = StoreCategory::query()->whereIn('id', $direct->keys())->pluck('path', 'id');
            $counts = [];

            foreach ($direct as $categoryId => $n) {
                $counts[(int) $categoryId]['direct'] = (int) $n;

                foreach (array_filter(explode('/', (string) ($paths[$categoryId] ?? '/'.$categoryId.'/'))) as $ancestorId) {
                    $counts[(int) $ancestorId]['rollup'] = ($counts[(int) $ancestorId]['rollup'] ?? 0) + (int) $n;
                }
            }

            foreach ($counts as $id => $row) {
                $counts[$id] = ['direct' => $row['direct'] ?? 0, 'rollup' => $row['rollup'] ?? 0];
            }

            return $counts;
        });
    }

    public static function forgetCounts(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ---- App\Contracts\CategoryTree: the lookups search, recommendations and collections use (D-50) -------------------
    // One tree, one implementation: discovery used to read store_categories through a second class that ignored the
    // materialised path and the on/off switch. These read the same table through the same path column.

    public function withDescendants(int $categoryId): array
    {
        $all = $this->all();

        if (! isset($all[$categoryId])) {
            return [$categoryId];
        }

        $prefix = $all[$categoryId]['path'] ?: '/'.$categoryId.'/';

        return array_values(array_map(
            fn (array $row) => $row['id'],
            array_filter($all, fn (array $row) => str_starts_with((string) $row['path'], $prefix)),
        )) ?: [$categoryId];
    }

    public function find(int $categoryId): ?array
    {
        return $this->all()[$categoryId] ?? null;
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->all() as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }

        return null;
    }

    public function many(array $categoryIds): array
    {
        return array_intersect_key($this->all(), array_flip($categoryIds));
    }

    public function path(int $categoryId): array
    {
        $all = $this->all();
        $path = [];

        for ($row = $all[$categoryId] ?? null, $guard = 0; $row !== null && $guard++ < 12; $row = $row['parent_id'] !== null ? ($all[$row['parent_id']] ?? null) : null) {
            array_unshift($path, $row['name']);
        }

        return $path;
    }

    public function forget(): void
    {
        $this->all = null;
    }

    /** @return array<int, array{id: int, name: string, slug: string, parent_id: int|null, path: string|null, depth: int, is_active: bool}> */
    private function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $this->all = [];

        foreach (StoreCategory::query()->get(['id', 'name', 'slug', 'parent_id', 'path', 'depth', 'is_active']) as $row) {
            $this->all[(int) $row->id] = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'parent_id' => $row->parent_id !== null ? (int) $row->parent_id : null,
                'path' => $row->path,
                'depth' => (int) $row->depth,
                'is_active' => (bool) $row->is_active,
            ];
        }

        return $this->all;
    }

    private function uniqueSlug(string $slug): string
    {
        $n = 2;

        while (StoreCategory::query()->where('slug', "{$slug}-{$n}")->exists()) {
            $n++;
        }

        return "{$slug}-{$n}";
    }
}
