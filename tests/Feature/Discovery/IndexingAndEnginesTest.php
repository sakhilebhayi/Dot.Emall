<?php

namespace Tests\Feature\Discovery;

use App\Models\SearchSynonym;
use App\Services\Catalogue\CategoryTree;
use App\Services\Search\PortableSearch;
use App\Services\Search\PostgresSearch;
use App\Services\Search\QueryUnderstanding;
use App\Services\Search\SearchCapabilities;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchRequest;
use App\Services\Search\Synonyms;
use App\Services\Search\Text;
use App\Services\Search\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

class IndexingAndEnginesTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
    }

    public function test_the_default_synonyms_are_seeded_by_migration_and_can_be_edited(): void
    {
        $this->assertGreaterThan(40, DB::table('search_synonyms')->count());
        $synonyms = app(Synonyms::class);

        $this->assertContains('notebook', $synonyms->equivalents('laptop'));
        $this->assertContains(Text::index('takkies'), $synonyms->equivalents('sneaker'), 'South African terms are in the starting set');
        $this->assertContains('bbq', $synonyms->equivalents('braai'));
        $this->assertContains('refrigerator', $synonyms->equivalents('fridge'));

        SearchSynonym::create(['group_key' => 'sofa', 'term' => 'Lounge Suite', 'note' => 'added by an operator']);

        $this->assertContains('lounge suite', app(Synonyms::class)->equivalents('sofa'), 'an edit is picked up at once');
    }

    public function test_reindex_builds_documents_vocabulary_and_units_sold(): void
    {
        $store = $this->store();
        $tvs = $this->category('Televisions');
        $p = $this->listing($store, 'Samsung 55 Inch Smart TV', 11999.0, ['category_id' => $tvs->id], 'Samsung');
        DB::table('search_documents')->delete();
        DB::table('search_terms')->delete();

        $this->artisan('emall:search-reindex')->expectsOutputToContain('Indexed 1 listings')->assertSuccessful();

        $doc = DB::table('search_documents')->where('product_id', $p->id)->first();
        $this->assertSame('samsung 55 inch smart tv', $doc->title_n);
        $this->assertSame('samsung', $doc->brand_n);
        $this->assertSame('television', $doc->category_n);
        $this->assertTrue(DB::table('search_terms')->where('kind', 'word')->where('term', 'smart')->exists());
        $this->assertTrue(DB::table('search_terms')->where('kind', 'brand')->where('term', 'samsung')->exists());
        $this->assertTrue(DB::table('search_terms')->where('kind', 'category')->where('term', 'television')->exists());
    }

    public function test_reindex_is_repeatable_and_removes_archived_listings(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Alpha Kettle', 100.0);
        $b = $this->listing($store, 'Beta Kettle', 100.0);

        $this->artisan('emall:search-reindex')->assertSuccessful();
        $this->artisan('emall:search-reindex')->assertSuccessful();
        $this->assertSame(2, DB::table('search_documents')->count());

        $b->delete();
        $this->assertSame(1, DB::table('search_documents')->count(), 'archiving removes the document at once');
        $this->artisan('emall:search-reindex')->assertSuccessful();
        $this->assertSame([$a->id], DB::table('search_documents')->pluck('product_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_the_stale_sweep_picks_up_missing_and_edited_listings(): void
    {
        config(['discovery.search.observe' => false]);
        $store = $this->store();
        $a = $this->listing($store, 'Alpha Kettle', 100.0);
        $this->assertSame(0, DB::table('search_documents')->count(), 'with the observer off nothing is indexed');

        $this->artisan('emall:search-reindex', ['--stale' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('search_documents')->count());

        $this->travel(5)->minutes();
        $a->update(['name' => 'Alpha Teapot']);
        $this->artisan('emall:search-reindex', ['--stale' => true])->assertSuccessful();

        $this->assertSame('alpha teapot', DB::table('search_documents')->value('title_n'));
    }

    public function test_renaming_a_store_reindexes_its_listings_and_a_category_rename_too(): void
    {
        $store = $this->store('Old Name Traders');
        $cat = $this->category('Gadgets');
        $p = $this->listing($store, 'Thing One', 10.0, ['category_id' => $cat->id]);

        $store->update(['name' => 'Karoo Traders']);
        $this->assertStringContainsString('karoo', DB::table('search_documents')->where('product_id', $p->id)->value('store_n'));

        $cat->update(['name' => 'Gizmos']);
        $this->assertStringContainsString('gizmo', DB::table('search_documents')->where('product_id', $p->id)->value('category_n'));
    }

    public function test_the_category_tree_walks_descendants(): void
    {
        $root = $this->category('Electronics');
        $child = $this->category('Laptops', $root);
        $grandchild = $this->category('Gaming Laptops', $child);
        $this->category('Furniture');
        $tree = new CategoryTree;

        $this->assertEqualsCanonicalizing([$root->id, $child->id, $grandchild->id], $tree->withDescendants($root->id));
        $this->assertSame(['Electronics', 'Laptops', 'Gaming Laptops'], $tree->path($grandchild->id));
        $this->assertSame($child->id, $tree->findBySlug('laptops')['id']);
    }

    public function test_selecting_a_parent_category_includes_its_children(): void
    {
        $store = $this->store();
        $root = $this->category('Electronics');
        $child = $this->category('Laptops', $root);
        $a = $this->listing($store, 'Lenovo Laptop', 100.0, ['category_id' => $child->id]);
        $b = $this->listing($store, 'Cable', 10.0, ['category_id' => $root->id]);
        $this->listing($store, 'Armchair', 500.0);
        $this->reindex();

        $result = app(SearchEngine::class)->search(new SearchRequest(categoryId: $root->id));

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $result->productIds());
    }

    public function test_the_portable_engine_gives_the_same_answers_on_any_database(): void
    {
        $store = $this->store();
        $cat = $this->category('Laptops');
        $a = $this->listing($store, 'Lenovo Laptop 15', 8999.0, ['category_id' => $cat->id], 'Lenovo');
        $this->listing($store, 'Plain Spoon', 20.0);
        $this->reindex();
        $portable = app(PortableSearch::class);

        $this->assertSame([$a->id], $portable->search(new SearchRequest(text: 'lenovo laptop under R10000'))->productIds());
        $this->assertSame([$a->id], $portable->search(new SearchRequest(text: 'LAPTOPS'))->productIds());
        $this->assertSame('Lenovo Laptop 15', $portable->suggestProducts('lenov')[0]['title']);
        $this->assertSame('portable', $portable->name());
    }

    public function test_capabilities_are_reported_and_cached(): void
    {
        $report = app(SearchCapabilities::class)->report();

        $this->assertSame(DB::connection()->getDriverName(), $report['driver']);
        $this->assertContains($report['engine'], ['postgres', 'portable']);
        $this->artisan('emall:search-reindex', ['--status' => true])->expectsOutputToContain('engine')->assertSuccessful();
    }

    public function test_forcing_the_portable_engine_by_config(): void
    {
        config(['discovery.search.engine' => 'portable']);
        app()->forgetInstance(SearchCapabilities::class);

        $this->assertFalse(app(SearchCapabilities::class)->fullText());
    }

    public function test_typo_tolerance_works_without_trigram_support(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Wireless Headphones', 999.0);
        $this->reindex();

        $result = app(PortableSearch::class)->search(new SearchRequest(text: 'wireles headfones'));

        $this->assertSame([$a->id], $result->productIds());
        $this->assertSame('wireles headfones', $result->correctedFrom);
        $this->assertStringContainsString('wireless', (string) $result->didYouMean);
    }

    public function test_a_host_without_extensions_behaves_the_same(): void
    {
        // Pretend the database has neither the tsvector column nor pg_trgm, whatever it really has.
        $this->app->instance(SearchCapabilities::class, new class extends SearchCapabilities
        {
            public function fullText(): bool
            {
                return false;
            }

            public function trigram(): bool
            {
                return false;
            }
        });
        foreach ([Vocabulary::class, QueryUnderstanding::class, SearchEngine::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $store = $this->store();
        $a = $this->listing($store, 'Wireless Headphones', 999.0);
        $this->reindex();
        $engine = app(SearchEngine::class);

        $this->assertInstanceOf(PortableSearch::class, $engine);
        $this->assertNotInstanceOf(PostgresSearch::class, $engine);
        $this->assertSame([$a->id], $engine->search(new SearchRequest(text: 'wireles headfones'))->productIds());
        $this->assertSame('headphone', app(Vocabulary::class)->correct('headfone'));
    }

    public function test_nothing_matching_gives_relaxations_that_name_the_filter_costing_results(): void
    {
        $store = $this->store();
        $this->listing($store, 'Garden Spade', 300.0, ['stock' => 5]);
        $this->reindex();

        $result = app(PortableSearch::class)->search(new SearchRequest(text: 'spade', priceMax: 100.0));

        $this->assertTrue($result->isEmpty());
        $this->assertSame('price', $result->relaxations[0]['remove']);
        $this->assertSame(1, $result->relaxations[0]['count']);
    }

    public function test_minimum_threshold_for_best_sellers_is_enforced_and_flagged(): void
    {
        $store = $this->store();
        $few = $this->listing($store, 'Few Sales Item', 10.0);
        $many = $this->listing($store, 'Many Sales Item', 10.0);
        $this->reindex();
        DB::table('search_documents')->where('product_id', $few->id)->update(['sold_count' => 2]);
        DB::table('search_documents')->where('product_id', $many->id)->update(['sold_count' => 5]);

        $result = app(PortableSearch::class)->search(new SearchRequest(text: 'sales', sort: 'best_selling'));

        $this->assertSame([$many->id, $few->id], $result->productIds());
        $this->assertStringContainsString('at least 3 sold', (string) $result->sortNote);
    }
}
