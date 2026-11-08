<?php

namespace Tests\Feature\Integration;

use App\Contracts\CatalogueSource;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Services\Catalogue\ProductAttributes;
use App\Services\Catalogue\ProductVariants;
use App\Services\Discovery\DatabaseCatalogueSource;
use App\Services\Search\QueryUnderstanding;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchIndexer;
use App\Services\Search\SearchRequest;
use App\Services\Search\SearchResult;
use App\Services\Search\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Search over the REAL commerce tables (D-50): the seeded taxonomy, brands, product_attributes, variants and the
 * store's region and shipping, with no fake catalogue. This is the proof that data A and data B are one foundation.
 */
class RealCatalogueSearchTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Store $capeStore;

    protected function setUp(): void
    {
        parent::setUp();
        config(['discovery.search.facet_cache_seconds' => 0]);
        Artisan::call('emall:seed-categories');

        $this->store = Store::factory()->create(['name' => 'Karoo Electronics', 'region' => 'Gauteng']);
        $this->capeStore = Store::factory()->create(['name' => 'Cape Gadgets', 'region' => 'Western Cape']);
    }

    private function category(string $slug): StoreCategory
    {
        return StoreCategory::query()->where('slug', $slug)->firstOrFail();
    }

    /** @param  array<string, scalar>  $attributes */
    private function product(string $name, float $price, string $categorySlug, ?string $brand, array $attributes = [], ?Store $store = null, int $stock = 10): Product
    {
        $product = Product::factory()->create([
            'store_id' => ($store ?? $this->store)->id,
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
            'category_id' => $this->category($categorySlug)->id,
            'brand_id' => $brand !== null ? Brand::named($brand)->id : null,
        ]);

        if ($attributes !== []) {
            app(ProductAttributes::class)->sync($product->refresh(), $attributes);
        }

        return $product->refresh();
    }

    private function search(array $params): SearchResult
    {
        app(SearchIndexer::class)->rebuild();
        Vocabulary::forget();

        return app(SearchEngine::class)->search(SearchRequest::fromArray($params));
    }

    public function test_a_laptop_with_ram_and_processor_attributes_appears_under_the_attribute_facets(): void
    {
        $a = $this->product('Lenovo IdeaPad 15', 8999, 'laptops', 'Lenovo', ['ram' => '16', 'storage' => '512', 'storage_type' => 'SSD', 'processor' => 'Intel Core i5', 'screen_size' => '15.6']);
        $b = $this->product('HP 250 G9', 6499, 'laptops', 'HP', ['ram' => '8', 'storage' => '256', 'storage_type' => 'SSD', 'processor' => 'Intel Core i3', 'screen_size' => '15.6']);
        $this->product('Dell Inspiron', 11999, 'laptops', 'Dell', ['ram' => '16', 'storage' => '1000', 'processor' => 'AMD Ryzen 7']);

        $result = $this->search(['category' => 'laptops']);

        $this->assertSame(3, $result->total);
        $ram = collect($result->facets['attributes']['ram']['values'])->mapWithKeys(fn ($v) => [$v['value'] => $v['count']]);
        $this->assertSame('Memory (RAM)', $result->facets['attributes']['ram']['label'], 'the label the seller sees in the product form');
        $this->assertSame(['16 gb' => 2, '8 gb' => 1], $ram->mapWithKeys(fn ($c, $v) => [strtolower($v) => $c])->all());
        $this->assertArrayHasKey('processor', $result->facets['attributes']);

        $filtered = $this->search(['category' => 'laptops', 'attr' => ['ram' => ['16 GB']]]);
        $this->assertEqualsCanonicalizing([$a->id, Product::where('name', 'Dell Inspiron')->value('id')], $filtered->productIds());
        $this->assertNotContains($b->id, $filtered->productIds());
    }

    public function test_the_brand_comes_from_the_brands_table_and_is_a_facet_and_a_search_word(): void
    {
        $lenovo = $this->product('IdeaPad 15', 8999, 'laptops', 'Lenovo', ['ram' => '8']);
        $this->product('Pavilion 14', 9999, 'laptops', 'HP', ['ram' => '8']);

        $byWord = $this->search(['q' => 'lenovo']);
        $this->assertSame([$lenovo->id], $byWord->productIds());
        $this->assertSame('Lenovo', $byWord->items[0]->brand);

        $facet = collect($this->search(['category' => 'laptops'])->facets['brands'])->pluck('count', 'label')->all();
        $this->assertSame(['HP' => 1, 'Lenovo' => 1], $facet);
    }

    public function test_samsung_55_inch_tv_under_r15000_resolves_to_real_filters(): void
    {
        $match = $this->product('Samsung 55 Inch Crystal UHD TV', 12999, 'tv-audio-video', 'Samsung', ['screen_size' => '55', 'resolution' => '4K']);
        $this->product('Samsung 65 Inch QLED TV', 21999, 'tv-audio-video', 'Samsung', ['screen_size' => '65', 'resolution' => '4K']);
        $this->product('Samsung 55 Inch Premium TV', 18999, 'tv-audio-video', 'Samsung', ['screen_size' => '55', 'resolution' => '4K']);
        $this->product('LG 55 Inch UHD TV', 13499, 'tv-audio-video', 'LG', ['screen_size' => '55', 'resolution' => '4K']);
        $this->product('Samsung Galaxy A15', 3999, 'smartphones', 'Samsung', ['storage' => '128']);
        app(SearchIndexer::class)->rebuild();
        Vocabulary::forget();

        $parsed = app(QueryUnderstanding::class)->parse('samsung 55 inch tv under R15,000');

        $this->assertSame('Samsung', $parsed->inferred['brand']);
        $this->assertSame(15000.0, $parsed->inferred['price_max']);
        $this->assertSame(['screen_size' => ['55 inch']], $parsed->inferred['attributes']);
        $this->assertSame($this->category('tv-audio-video')->id, $parsed->inferred['category_id'], '"tv" finds "TV, audio and video" because each part of a compound name is a phrase');

        $result = app(SearchEngine::class)->search(SearchRequest::fromArray(['q' => 'samsung 55 inch tv under R15,000']));

        $this->assertSame([$match->id], $result->productIds());
        $chips = collect($result->appliedFilters)->pluck('key')->all();
        $this->assertContains('brand', $chips);
        $this->assertContains('price', $chips);
        $this->assertContains('category', $chips);
        $this->assertContains('attr.screen_size', $chips);
    }

    public function test_a_name_shared_by_several_categories_does_not_pick_one_of_them(): void
    {
        $women = $this->product('Cotton Dress', 399, 'women-clothing', null);
        $men = $this->product('Cotton Shirt', 299, 'men-clothing', null);
        app(SearchIndexer::class)->rebuild();
        Vocabulary::forget();

        $bare = app(QueryUnderstanding::class)->parse('clothing');
        $this->assertArrayNotHasKey('category_id', $bare->inferred, 'three categories are called Clothing');
        $this->assertEqualsCanonicalizing([$women->id, $men->id], app(SearchEngine::class)->search(SearchRequest::fromArray(['q' => 'clothing']))->productIds());

        $qualified = app(QueryUnderstanding::class)->parse('men clothing');
        $this->assertSame($this->category('men-clothing')->id, $qualified->inferred['category_id']);
    }

    public function test_variant_options_are_searchable_and_listed_as_facets(): void
    {
        $shirt = $this->product('Polo Shirt', 250, 'men-clothing', null, ['material' => 'Cotton']);
        app(ProductVariants::class)->defineOptions($shirt, ['Size' => ['S', 'M', 'L'], 'Colour' => ['Navy', 'Red']]);

        foreach ([['S', 'Navy', 250], ['M', 'Navy', 260], ['L', 'Red', 270]] as [$size, $colour, $price]) {
            app(ProductVariants::class)->saveVariant($shirt, ['Size' => $size, 'Colour' => $colour], ['price' => $price, 'stock' => 4]);
        }

        $plain = $this->product('Plain Shirt', 150, 'men-clothing', null, ['colour' => 'Black']);
        app(SearchIndexer::class)->rebuild();
        Vocabulary::forget();

        $red = app(SearchEngine::class)->search(SearchRequest::fromArray(['q' => 'red shirt']));
        $this->assertSame([$shirt->id], $red->productIds(), 'only the polo comes in red');

        $facets = $this->search(['category' => 'men-clothing'])->facets['attributes'];
        $colours = collect($facets['colour']['values'])->pluck('count', 'value')->all();
        $this->assertEqualsCanonicalizing(['Navy' => 1, 'Red' => 1, 'Black' => 1], $colours);
        $this->assertSame(1, collect($facets['size']['values'])->firstWhere('value', 'M')['count']);
        $this->assertNotContains($plain->id, $this->search(['category' => 'men-clothing', 'attr' => ['colour' => ['Red']]])->productIds());
    }

    public function test_the_product_row_carries_the_cheapest_variant_price_and_total_stock_so_price_and_availability_filters_agree(): void
    {
        $shoe = $this->product('Runner Sneaker', 900, 'men-shoes', null, ['shoe_size' => '9']);
        app(ProductVariants::class)->defineOptions($shoe, ['Size' => ['8', '9', '10']]);
        app(ProductVariants::class)->saveVariant($shoe, ['Size' => '8'], ['price' => 850, 'stock' => 0]);
        app(ProductVariants::class)->saveVariant($shoe, ['Size' => '9'], ['price' => 900, 'stock' => 3]);
        app(ProductVariants::class)->saveVariant($shoe, ['Size' => '10'], ['price' => 950, 'stock' => 0]);

        $this->assertSame(850.0, (float) $shoe->refresh()->price, 'from the cheapest active variant');
        $this->assertSame(3, $shoe->stock, 'the sum of the variants');

        $this->assertContains($shoe->id, $this->search(['category' => 'men-shoes', 'price_max' => 860])->productIds());
        $this->assertContains($shoe->id, $this->search(['category' => 'men-shoes', 'in_stock' => 1])->productIds());
    }

    public function test_region_and_delivery_estimate_come_from_the_store_and_its_shipping_methods(): void
    {
        $cape = $this->product('Cape Laptop Bag', 349, 'laptops', null, [], $this->capeStore);
        $this->product('Joburg Laptop Bag', 349, 'laptops', null, [], $this->store);
        ShippingMethod::factory()->create(['store_id' => $this->capeStore->id, 'name' => 'Courier', 'type' => 'flat', 'price' => 75, 'handling_days' => 1, 'window_min_days' => 2, 'window_max_days' => 4]);
        ShippingMethod::factory()->create(['store_id' => $this->capeStore->id, 'name' => 'Pickup', 'type' => 'collection', 'price' => 0]);

        $this->assertSame([$cape->id], $this->search(['category' => 'laptops', 'region' => ['Western Cape']])->productIds());

        $source = app(CatalogueSource::class);
        $this->assertInstanceOf(DatabaseCatalogueSource::class, $source);
        $this->assertSame(['min' => 3, 'max' => 5], $source->deliveryEstimate($cape->id), 'one handling day plus the 2 to 4 day window, working days');
        $this->assertNull($source->deliveryEstimate(Product::where('name', 'Joburg Laptop Bag')->value('id')), 'a store that set no delivery method is never given a guess');
    }

    public function test_editing_a_spec_or_a_brand_updates_the_search_document_without_a_full_rebuild(): void
    {
        $laptop = $this->product('Acer Aspire', 7999, 'laptops', 'Acer', ['ram' => '8']);
        app(SearchIndexer::class)->rebuild();
        $this->assertStringContainsString('8 gb', DB::table('search_documents')->where('product_id', $laptop->id)->value('attributes_n'));

        app(ProductAttributes::class)->sync($laptop, ['ram' => '32']);
        $document = DB::table('search_documents')->where('product_id', $laptop->id)->first();
        $this->assertStringContainsString('32 gb', $document->attributes_n);
        $this->assertStringNotContainsString('8 gb', $document->attributes_n);

        Brand::where('name', 'Acer')->first()->update(['name' => 'Acer Group']);
        $this->assertSame('Acer Group', DB::table('search_documents')->where('product_id', $laptop->id)->value('brand'));

        $laptop->update(['brand_id' => Brand::named('Asus')->id]);
        $this->assertSame('Asus', DB::table('search_documents')->where('product_id', $laptop->id)->value('brand'));
        $this->assertStringContainsString('electronic and computer laptop', DB::table('search_documents')->where('product_id', $laptop->id)->value('category_n'), 'the whole category path is searchable');
    }
}
