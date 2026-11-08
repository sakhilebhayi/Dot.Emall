<?php

namespace App\Contracts;

/**
 * The category hierarchy as discovery needs it. App\Services\Catalogue\CategoryTree implements it over store_categories
 * and its materialised path (D-50); tests may bind another.
 */
interface CategoryTree
{
    /** The category and every descendant of it. @return list<int> */
    public function withDescendants(int $categoryId): array;

    /**
     * Every category, by id.
     *
     * @return array<int, array{id: int, name: string, slug: string, parent_id: int|null}>
     */
    public function all(): array;

    /** @return array<string, mixed>|null {id, name, slug, parent_id} */
    public function find(int $categoryId): ?array;

    /** @return array<string, mixed>|null {id, name, slug, parent_id} */
    public function findBySlug(string $slug): ?array;

    /**
     * Names for many categories at once.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, array{id: int, name: string, slug: string, parent_id: int|null}>
     */
    public function many(array $categoryIds): array;

    /** Drop anything remembered in memory (called when a category is created, renamed or moved). */
    public function forget(): void;

    /** Root-first names of the path to the category, e.g. ["Electronics", "Laptops"]. @return list<string> */
    public function path(int $categoryId): array;
}
