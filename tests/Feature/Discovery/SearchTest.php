<?php

namespace Tests\Feature\Discovery;

use App\Services\Search\PortableSearch;
use App\Services\Search\PostgresSearch;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use App\Services\Search\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
    }

    private function seedCatalogue(): array
    {
        $store = $this->store('Vaal Traders');
        $tvs = $this->category('Televisions');
        $laptops = $this->category('Laptops');
        $phones = $this->category('Cell Phones');

        $p = [];
        $p['tv55'] = $this->listing($store, 'Samsung 55 Inch Smart TV', 11999.00, ['category_id' => $tvs->id, 'stock' => 5], 'Samsung');
        $p['tv43'] = $this->listing($store, 'Samsung 43 Inch Smart TV', 7499.00, ['category_id' => $tvs->id, 'stock' => 0], 'Samsung');
        $p['lgtv'] = $this->listing($store, 'LG 55 Inch OLED Television', 21999.00, ['category_id' => $tvs->id, 'stock' => 2], 'LG');
        $p['lap'] = $this->listing($store, 'Lenovo IdeaPad Laptop 15', 8999.00, ['category_id' => $laptops->id, 'stock' => 8], 'Lenovo');
        $p['nb'] = $this->listing($store, 'HP Pavilion Notebook', 12999.00, ['category_id' => $laptops->id, 'stock' => 3], 'HP');
        $p['phone'] = $this->listing($store, 'Samsung Galaxy A54 Smartphone', 6499.00, ['category_id' => $phones->id, 'stock' => 9], 'Samsung');
        $p['cafe'] = $this->listing($store, 'Café Möller Coffee Beans 1kg', 189.00, ['stock' => 40]);

        $this->catalogue->attribute($p['tv55']->id, 'screen_size', 'Screen size', '55', 55.0, 'inch');
        $this->catalogue->attribute($p['tv43']->id, 'screen_size', 'Screen size', '43', 43.0, 'inch');
        $this->catalogue->attribute($p['lgtv']->id, 'screen_size', 'Screen size', '55', 55.0, 'inch');
        $this->catalogue->attribute($p['tv55']->id, 'colour', 'Colour', 'Black');
        $this->catalogue->attribute($p['lgtv']->id, 'colour', 'Colour', 'Silver');

        $this->reindex();

        return $p;
    }

    private function search(array|string $input): SearchResult
    {
        $request = is_string($input) ? new SearchRequest(text: $input) : SearchRequest::fromArray($input);

        return app(SearchEngine::class)->search($request);
    }

    public function test_engine_is_chosen_by_capability(): void
    {
        $expected = DB::connection()->getDriverName() === 'pgsql' ? PostgresSearch::class : PortableSearch::class;

        $this->assertInstanceOf($expected, app(SearchEngine::class));
    }

    public function test_matching_ignores_case_accents_and_plurals(): void
    {
        $p = $this->seedCatalogue();

        $this->assertSame([$p['cafe']->id], $this->search('MOLLER cafe')->productIds());
        $this->assertSame([$p['cafe']->id], $this->search('Möller Café beans')->productIds());
        $this->assertContains($p['lap']->id, $this->search('laptops')->productIds());
    }

    public function test_synonyms_find_the_other_word(): void
    {
        $p = $this->seedCatalogue();

        $ids = $this->search(['q' => 'notebook', 'literal' => '1'])->productIds();

        $this->assertEqualsCanonicalizing([$p['lap']->id, $p['nb']->id], $ids);
        $hit = collect($this->search(['q' => 'notebook', 'literal' => '1'])->items)->firstWhere(fn ($h) => $h->product->id === $p['lap']->id);
        $this->assertStringContainsString('counts as', $hit->why());
    }

    public function test_exact_title_words_rank_above_description_only_matches(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Garden Hose 20m', 250.00, ['description' => 'Strong.']);
        $b = $this->listing($store, 'Watering Can', 90.00, ['description' => 'Works well with a hose or a tap hose.']);
        $this->reindex();

        $this->assertSame([$a->id, $b->id], $this->search('hose')->productIds());
    }

    public function test_typo_is_corrected_with_an_honest_note(): void
    {
        $p = $this->seedCatalogue();

        $result = $this->search('samsnug');

        $this->assertNotEmpty($result->items);
        $this->assertSame('samsnug', $result->correctedFrom);
        $this->assertStringContainsString('samsung', (string) $result->didYouMean);
        $this->assertContains($p['tv55']->id, $result->productIds());
        $this->assertStringContainsString('Showing results for', implode(' ', $result->notes));
    }

    public function test_nothing_is_invented_when_nothing_matches(): void
    {
        $this->seedCatalogue();

        $result = $this->search('zxqvbn');

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->items);
    }

    public function test_query_is_understood_into_brand_category_attribute_and_price(): void
    {
        $p = $this->seedCatalogue();

        $result = $this->search('samsung 55 inch tv');

        $this->assertSame([$p['tv55']->id], $result->productIds());
        $kinds = array_column($result->understood, 'kind');
        $this->assertContains('brand', $kinds);
        $this->assertContains('category', $kinds);
        $this->assertContains('attribute', $kinds);
        $this->assertTrue(collect($result->appliedFilters)->every(fn ($chip) => $chip['inferred']));
    }

    public function test_price_phrases_become_a_price_filter(): void
    {
        $p = $this->seedCatalogue();

        $result = $this->search('laptop under R10,000');

        $this->assertSame([$p['lap']->id], $result->productIds());
        $price = collect($result->appliedFilters)->firstWhere('key', 'price');
        $this->assertTrue($price['inferred']);
        $this->assertStringContainsString('10,000', $price['label']);
    }

    public function test_an_inferred_filter_never_hides_results(): void
    {
        $p = $this->seedCatalogue();

        // "55 inch" is a real attribute, but nothing is a 55 inch Galaxy: the search must still answer, and say how.
        $result = $this->search('galaxy 55 inch');

        $this->assertContains($p['phone']->id, $result->productIds());
        $this->assertTrue($result->relaxed);
        $this->assertStringContainsString('some of them', implode(' ', $result->notes));
    }

    public function test_explicit_filters_win_over_inferred_ones(): void
    {
        $p = $this->seedCatalogue();

        $result = $this->search(['q' => 'samsung tv', 'brand' => ['LG']]);

        $ids = $result->productIds();
        $this->assertNotContains($p['tv55']->id, $ids);
    }

    public function test_facets_count_real_rows_and_price_ranges_come_from_the_result(): void
    {
        $p = $this->seedCatalogue();
        $tvs = DB::table('store_categories')->where('slug', 'televisions')->value('id');

        $result = $this->search(['category' => $tvs]);

        $this->assertSame(3, $result->total);
        $brands = collect($result->facets['brands'])->pluck('count', 'label')->all();
        $this->assertSame(['Samsung' => 2, 'LG' => 1], $brands);
        $this->assertSame(7499.0, $result->facets['price']['min']);
        $this->assertSame(21999.0, $result->facets['price']['max']);
        $this->assertSame(3, array_sum(array_column($result->facets['price']['buckets'], 'count')));
        $this->assertSame(2, $result->facets['availability']['in_stock']);
        $this->assertSame(1, $result->facets['availability']['out_of_stock']);
        $this->assertArrayHasKey('screen_size', $result->facets['attributes']);
        $sizes = collect($result->facets['attributes']['screen_size']['values'])->pluck('count', 'value')->all();
        $this->assertSame(['55 inch' => 2, '43 inch' => 1], $sizes);
    }

    public function test_selecting_a_brand_keeps_the_other_brands_in_the_facet(): void
    {
        $this->seedCatalogue();
        $tvs = DB::table('store_categories')->where('slug', 'televisions')->value('id');

        $result = $this->search(['category' => $tvs, 'brand' => ['LG']]);

        $this->assertSame(1, $result->total);
        $this->assertSame(['Samsung', 'LG'], collect($result->facets['brands'])->pluck('label')->all());
    }

    public function test_sorts_are_stable_and_honest(): void
    {
        $p = $this->seedCatalogue();

        $asc = $this->search(['q' => 'inch', 'sort' => 'price_asc'])->productIds();
        $this->assertSame([$p['tv43']->id, $p['tv55']->id, $p['lgtv']->id], $asc);

        $best = $this->search(['sort' => 'best_selling']);
        $this->assertStringContainsString('Only products with at least 3 sold', (string) $best->sortNote);
    }

    public function test_pagination_does_not_repeat_or_skip(): void
    {
        $store = $this->store();

        foreach (range(1, 30) as $i) {
            $this->listing($store, 'Widget model '.$i, 10 + ($i % 3));
        }

        $this->reindex();
        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, app(SearchEngine::class)->search(new SearchRequest(text: 'widget', sort: 'price_asc', page: $page, perPage: 12))->productIds());
        }

        $this->assertCount(30, $seen);
        $this->assertCount(30, array_unique($seen));
    }

    public function test_only_buyable_listings_are_found(): void
    {
        $store = $this->store();
        $inactiveStore = $this->store('Closed Shop', ['is_active' => false]);
        $this->listing($store, 'Visible Kettle', 300.0);
        $this->listing($store, 'Pending Kettle', 300.0, ['status' => 'pending_approval']);
        $this->listing($store, 'Hidden Kettle', 300.0, ['is_active' => false]);
        $this->listing($inactiveStore, 'Closed Kettle', 300.0);
        $gone = $this->listing($store, 'Archived Kettle', 300.0);
        $this->reindex();
        $gone->delete();

        $result = $this->search('kettle');

        $this->assertSame(['Visible Kettle'], collect($result->items)->map(fn ($h) => $h->product->name)->all());
    }

    public function test_a_stock_change_shows_without_reindexing(): void
    {
        $store = $this->store();
        $p = $this->listing($store, 'Camping Chair', 400.0, ['stock' => 3]);
        $this->reindex();

        $this->assertTrue($this->search('camping chair')->items[0]->inStock);

        $p->update(['stock' => 0]);

        $this->assertFalse($this->search('camping chair')->items[0]->inStock);
    }

    public function test_search_text_is_never_treated_as_syntax(): void
    {
        $this->seedCatalogue();

        foreach (["samsung' | !x", 'tv:* & (', '"; drop table products; --', '%_\\', '   '] as $hostile) {
            $result = $this->search($hostile);
            $this->assertIsInt($result->total);
        }

        $this->assertDatabaseCount('products', 7);
    }

    public function test_editing_a_listing_updates_its_search_text(): void
    {
        $store = $this->store();
        $p = $this->listing($store, 'Plain Mug', 50.0);
        $this->reindex();

        $this->assertTrue($this->search('teapot')->isEmpty());

        $p->update(['name' => 'Ceramic Teapot']);

        $this->assertSame([$p->id], $this->search('teapot')->productIds());
    }

    public function test_query_count_is_bounded(): void
    {
        $this->seedCatalogue();
        $tvs = DB::table('store_categories')->where('slug', 'televisions')->value('id');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->search(['q' => 'samsung', 'category' => $tvs]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(24, $count, "search ran {$count} queries");
    }
}
