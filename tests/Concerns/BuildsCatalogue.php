<?php

namespace Tests\Concerns;

use App\Contracts\CatalogueSource;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Services\Search\SearchIndexer;
use App\Services\Search\Vocabulary;
use App\Support\Actor;
use Illuminate\Support\Str;
use Tests\Support\FakeCatalogue;

/**
 * A small searchable catalogue for discovery tests: real stores, categories and products through the real models,
 * brands/attributes/regions through FakeCatalogue (those tables belong to another workstream), indexed by the real
 * SearchIndexer.
 */
trait BuildsCatalogue
{
    protected FakeCatalogue $catalogue;

    protected function setUpCatalogue(): void
    {
        $this->catalogue = new FakeCatalogue;
        $this->app->instance(CatalogueSource::class, $this->catalogue);
        $this->app->forgetInstance(SearchIndexer::class);
        config(['discovery.search.facet_cache_seconds' => 0]);
    }

    protected function category(string $name, ?StoreCategory $parent = null): StoreCategory
    {
        return StoreCategory::query()->create(['name' => $name, 'slug' => Str::slug($name), 'parent_id' => $parent?->id]);
    }

    protected function store(string $name = 'Vaal Traders', array $attributes = []): Store
    {
        return Store::factory()->create(array_merge(['name' => $name], $attributes));
    }

    protected function listing(Store $store, string $name, float $price, array $attributes = [], ?string $brand = null): Product
    {
        $product = Product::factory()->create(array_merge([
            'store_id' => $store->id,
            'name' => $name,
            'price' => $price,
            'description' => 'A '.$name.' for everyday use.',
        ], $attributes));

        if ($brand !== null) {
            $this->catalogue->brands[$product->id] = $brand;
        }

        return $product;
    }

    protected function reindex(): void
    {
        app(SearchIndexer::class)->rebuild();
        Vocabulary::forget();
    }

    protected function visitor(string $token = 'visitor-token-aaaaaaaaaaaaaaaa'): Actor
    {
        return Actor::forToken($token);
    }
}
