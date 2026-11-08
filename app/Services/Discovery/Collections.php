<?php

namespace App\Services\Discovery;

use App\Contracts\CategoryTree;
use App\Models\Collection;
use App\Models\Product;
use App\Models\User;
use App\Services\Community\StoreMembers;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as Rows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Editorial and seasonal collections, made by operators. A manual collection is a hand-picked list; a dynamic one is
 * a rule (category, brand, price range, attributes, in stock, rating, sort) re-run each time it is viewed, so it
 * never goes stale and never shows something that cannot be bought. Both show only buyable products, and a
 * collection is public only between its start and end dates.
 */
class Collections
{
    /** Keys a dynamic rule may hold. */
    public const RULE_KEYS = ['category', 'brand', 'price_min', 'price_max', 'in_stock', 'rating', 'attr', 'region', 'sort', 'q'];

    public function __construct(
        private readonly SearchEngine $engine,
        private readonly CategoryTree $categories,
        private readonly StoreMembers $members,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, type?: string, rule?: array<string, mixed>|null, starts_at?: mixed, ends_at?: mixed, visibility?: string, product_ids?: list<int>}  $data
     *
     * @throws ValidationException
     */
    public function create(User $operator, array $data): Collection
    {
        $this->requireOperator($operator);
        $attributes = $this->validated($data);

        $collection = DB::transaction(function () use ($operator, $attributes, $data) {
            $collection = new Collection($attributes + ['curator_id' => $operator->id, 'slug' => $this->slugFor($attributes['title'])]);
            $collection->position = (int) Collection::query()->max('position') + 1;
            $collection->save();

            if (($attributes['type'] ?? Collection::MANUAL) === Collection::MANUAL) {
                $this->setProducts($collection, $data['product_ids'] ?? []);
            }

            return $collection;
        });

        return $collection->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(User $operator, Collection $collection, array $data): Collection
    {
        $this->requireOperator($operator);
        $attributes = $this->validated($data + $collection->only(['title', 'description', 'type', 'rule', 'starts_at', 'ends_at', 'visibility']));

        DB::transaction(function () use ($collection, $attributes, $data) {
            $collection->fill($attributes)->save();

            if ($collection->type === Collection::MANUAL && array_key_exists('product_ids', $data)) {
                $this->setProducts($collection, $data['product_ids']);
            }
        });

        return $collection->refresh();
    }

    public function delete(User $operator, Collection $collection): void
    {
        $this->requireOperator($operator);
        $collection->delete();
    }

    /**
     * The collection's buyable products, in-stock first for a dynamic one, in the curator's order for a manual one.
     *
     * @return Rows<int, Product>
     */
    public function products(Collection $collection, int $limit = 24): Rows
    {
        if ($collection->type === Collection::DYNAMIC) {
            $request = SearchRequest::fromArray(['literal' => '1', 'sort' => 'newest'] + (array) $collection->rule)->with(['perPage' => $limit, 'page' => 1]);

            return collect($this->engine->search($request)->items)->map(fn ($hit) => $hit->product)->values();
        }

        $ids = DB::table('collection_products')->where('collection_id', $collection->id)->orderBy('position')->orderBy('id')->pluck('product_id');
        $products = Product::query()->browsable()->whereIn('id', $ids)->with(['store', 'images'])->get()->keyBy('id');

        return $ids->map(fn ($id) => $products->get($id))->filter()->take($limit)->values();
    }

    /**
     * Collections visible to the public right now that have something to show.
     *
     * @return Rows<int, array{collection: Collection, preview: Rows<int, Product>}>
     */
    public function live(int $limit = 6, int $preview = 4): Rows
    {
        return Collection::query()->live()->orderBy('position')->orderBy('id')->get()
            ->map(fn (Collection $c) => ['collection' => $c, 'preview' => $this->products($c, $preview)])
            ->filter(fn (array $row) => $row['preview']->isNotEmpty())->take($limit)->values();
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateRule(array $rule): array
    {
        $rule = array_intersect_key($rule, array_flip(self::RULE_KEYS));

        if (isset($rule['category']) && is_string($rule['category']) && ! ctype_digit($rule['category'])) {
            $found = $this->categories->findBySlug($rule['category']);

            if ($found === null) {
                throw ValidationException::withMessages(['rule' => 'That category does not exist.']);
            }

            $rule['category'] = $found['id'];
        }

        foreach (['price_min', 'price_max'] as $key) {
            if (isset($rule[$key]) && (! is_numeric($rule[$key]) || (float) $rule[$key] < 0)) {
                throw ValidationException::withMessages(['rule' => 'Prices in a rule must be numbers.']);
            }
        }

        if (isset($rule['sort']) && ! in_array($rule['sort'], SearchRequest::SORTS, true)) {
            throw ValidationException::withMessages(['rule' => 'Unknown sort.']);
        }

        if (array_diff(array_keys($rule), ['sort']) === []) {
            throw ValidationException::withMessages(['rule' => 'A dynamic collection needs at least one condition.']);
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 120) {
            throw ValidationException::withMessages(['title' => 'Give the collection a title of up to 120 characters.']);
        }

        $type = $data['type'] ?? Collection::MANUAL;

        if (! in_array($type, [Collection::MANUAL, Collection::DYNAMIC], true)) {
            throw ValidationException::withMessages(['type' => 'A collection is manual or dynamic.']);
        }

        $visibility = $data['visibility'] ?? 'draft';

        if (! in_array($visibility, ['draft', 'public'], true)) {
            throw ValidationException::withMessages(['visibility' => 'Choose draft or public.']);
        }

        $starts = ! empty($data['starts_at']) ? Carbon::parse($data['starts_at']) : null;
        $ends = ! empty($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;

        if ($starts && $ends && $ends->lte($starts)) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }

        return [
            'title' => $title,
            'description' => isset($data['description']) ? mb_substr(trim((string) $data['description']), 0, 2000) : null,
            'type' => $type,
            'rule' => $type === Collection::DYNAMIC ? $this->validateRule((array) ($data['rule'] ?? [])) : null,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'visibility' => $visibility,
        ];
    }

    /** @param  list<int>  $productIds */
    private function setProducts(Collection $collection, array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $valid = Product::query()->whereIn('id', $productIds)->pluck('id')->all();

        DB::table('collection_products')->where('collection_id', $collection->id)->delete();

        foreach (array_values(array_filter($productIds, fn ($id) => in_array($id, $valid, true))) as $position => $id) {
            DB::table('collection_products')->insert(['collection_id' => $collection->id, 'product_id' => $id, 'position' => $position]);
        }
    }

    private function slugFor(string $title): string
    {
        $base = Str::slug($title) ?: 'collection';
        $slug = $base;
        $n = 2;

        while (Collection::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function requireOperator(User $user): void
    {
        if (! $this->members->isOperator($user)) {
            throw ValidationException::withMessages(['collection' => 'Only the marketplace operator can manage collections.']);
        }
    }
}
