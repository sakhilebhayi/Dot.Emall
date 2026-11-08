<?php

namespace App\Services\Discovery;

use App\Contracts\CategoryTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The category tree as it exists today, in store_categories (id, name, slug, parent_id). The whole table is read once
 * per request (a marketplace has dozens of categories, not thousands) and walked in memory.
 */
class StoreCategoryTree implements CategoryTree
{
    /** @var array<int, array{id: int, name: string, slug: string, parent_id: int|null}>|null */
    private ?array $all = null;

    public function withDescendants(int $categoryId): array
    {
        $all = $this->all();

        if (! isset($all[$categoryId])) {
            return [$categoryId];
        }

        $children = [];

        foreach ($all as $row) {
            if ($row['parent_id'] !== null) {
                $children[$row['parent_id']][] = $row['id'];
            }
        }

        $found = [];
        $queue = [$categoryId];

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($found[$id])) {
                continue;
            }

            $found[$id] = true;

            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return array_keys($found);
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
        $guard = 0;

        for ($row = $all[$categoryId] ?? null; $row !== null && $guard++ < 12; $row = isset($row['parent_id']) ? ($all[$row['parent_id']] ?? null) : null) {
            array_unshift($path, $row['name']);
        }

        return $path;
    }

    public function forget(): void
    {
        $this->all = null;
    }

    /** @return array<int, array{id: int, name: string, slug: string, parent_id: int|null}> */
    private function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $this->all = [];

        if (! Schema::hasTable('store_categories')) {
            return $this->all;
        }

        foreach (DB::table('store_categories')->get(['id', 'name', 'slug', 'parent_id']) as $row) {
            $this->all[(int) $row->id] = [
                'id' => (int) $row->id,
                'name' => $row->name,
                'slug' => $row->slug,
                'parent_id' => $row->parent_id !== null ? (int) $row->parent_id : null,
            ];
        }

        return $this->all;
    }
}
