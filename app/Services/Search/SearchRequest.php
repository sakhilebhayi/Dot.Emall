<?php

namespace App\Services\Search;

use App\Contracts\CategoryTree;

/**
 * Everything a search can be asked: the typed text plus explicit filters, a sort and a page. Filters set here are
 * the person's own choices and always win over anything the engine infers from the text.
 */
final class SearchRequest
{
    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'newest', 'rating', 'best_selling'];

    /**
     * @param  list<string>  $brands  display names or searched forms
     * @param  list<int>  $storeIds
     * @param  list<string>  $regions
     * @param  array<string, list<string>>  $attributes  attribute key => values
     */
    public function __construct(
        public string $text = '',
        public ?int $categoryId = null,
        public array $brands = [],
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public bool $inStock = false,
        public ?int $minRating = null,
        public array $storeIds = [],
        public array $regions = [],
        public array $attributes = [],
        public string $sort = 'relevance',
        public int $page = 1,
        public int $perPage = 24,
        public bool $understand = true,
    ) {
        $this->text = trim(mb_substr($this->text, 0, (int) config('discovery.search.max_query_length', 100)));
        $this->sort = in_array($this->sort, self::SORTS, true) ? $this->sort : 'relevance';
        $this->page = max(1, min($this->page, (int) config('discovery.search.max_page', 200)));
        $this->perPage = max(1, min($this->perPage, 60));
    }

    /**
     * Reads the query-string shape the storefront uses: q, category (id or slug), brand[], price_min, price_max,
     * in_stock, rating, store[], region[], attr[key][], sort, page.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $list = fn ($value) => array_values(array_filter(array_map('strval', (array) $value), fn ($v) => $v !== ''));
        $category = $input['category'] ?? null;

        if (is_string($category) && ! ctype_digit($category) && $category !== '') {
            $category = app(CategoryTree::class)->findBySlug($category)['id'] ?? null;
        }

        $attributes = [];

        foreach ((array) ($input['attr'] ?? []) as $key => $values) {
            $values = $list($values);

            if ($values !== [] && is_string($key) && preg_match('/^[a-z0-9_]{1,64}$/', $key) === 1) {
                $attributes[$key] = $values;
            }
        }

        $number = fn ($value) => is_numeric($value) ? (float) $value : null;

        return new self(
            text: (string) ($input['q'] ?? $input['text'] ?? ''),
            categoryId: $category !== null && $category !== '' && is_numeric($category) ? (int) $category : null,
            brands: $list($input['brand'] ?? []),
            priceMin: $number($input['price_min'] ?? null),
            priceMax: $number($input['price_max'] ?? null),
            inStock: filter_var($input['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
            minRating: isset($input['rating']) && is_numeric($input['rating']) ? max(1, min(5, (int) $input['rating'])) : null,
            storeIds: array_map('intval', $list($input['store'] ?? [])),
            regions: $list($input['region'] ?? []),
            attributes: $attributes,
            sort: (string) ($input['sort'] ?? 'relevance'),
            page: (int) ($input['page'] ?? 1),
            perPage: (int) ($input['per_page'] ?? config('discovery.search.per_page', 24)),
            understand: ! filter_var($input['literal'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * The filters only (no text, sort or page), for saving a search and for comparing two searches.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter([
            'category' => $this->categoryId,
            'brand' => $this->brands,
            'price_min' => $this->priceMin,
            'price_max' => $this->priceMax,
            'in_stock' => $this->inStock ?: null,
            'rating' => $this->minRating,
            'store' => $this->storeIds,
            'region' => $this->regions,
            'attr' => $this->attributes,
        ], fn ($value) => $value !== null && $value !== [] && $value !== false);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'q' => $this->text,
            ...$this->filters(),
            'sort' => $this->sort !== 'relevance' ? $this->sort : null,
            'page' => $this->page > 1 ? $this->page : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    public function signature(): string
    {
        $array = $this->toArray();
        ksort($array);

        return sha1(json_encode([$array, $this->perPage, $this->understand]));
    }

    public function with(array $changes): self
    {
        $clone = clone $this;

        foreach ($changes as $property => $value) {
            $clone->{$property} = $value;
        }

        return $clone;
    }

    public function withoutFilter(string $key): self
    {
        return match ($key) {
            'category' => $this->with(['categoryId' => null]),
            'brand' => $this->with(['brands' => []]),
            'price' => $this->with(['priceMin' => null, 'priceMax' => null]),
            'in_stock' => $this->with(['inStock' => false]),
            'rating' => $this->with(['minRating' => null]),
            'store' => $this->with(['storeIds' => []]),
            'region' => $this->with(['regions' => []]),
            default => str_starts_with($key, 'attr.')
                ? $this->with(['attributes' => array_diff_key($this->attributes, [substr($key, 5) => true])])
                : $this,
        };
    }
}
