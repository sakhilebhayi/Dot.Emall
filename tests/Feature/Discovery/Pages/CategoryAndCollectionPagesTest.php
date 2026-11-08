<?php

namespace Tests\Feature\Discovery\Pages;

use App\Models\Collection;
use App\Models\User;
use App\Services\Catalogue\CategoryTree;
use App\Services\Discovery\Collections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

class CategoryAndCollectionPagesTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();

        $store = $this->store('Vaal Traders');
        $electronics = $this->category('Electronics');
        $laptops = $this->category('Laptops', $electronics);
        $phones = $this->category('Phones', $electronics);
        $empty = $this->category('Empty shelf');
        $electronics->update(['description' => 'Everything with a plug.']);
        $this->category('Hidden things')->update(['is_active' => false]);

        $this->p['lap1'] = $this->listing($store, 'Lenovo Notebook', 8999.00, ['category_id' => $laptops->id, 'stock' => 4], 'Lenovo');
        $this->p['lap2'] = $this->listing($store, 'HP Notebook', 6499.00, ['category_id' => $laptops->id, 'stock' => 4], 'HP');
        $this->p['ph'] = $this->listing($store, 'Galaxy Phone', 5999.00, ['category_id' => $phones->id, 'stock' => 2], 'Samsung');
        $this->p['hidden'] = $this->listing($store, 'Pending Notebook', 100.00, ['category_id' => $laptops->id, 'status' => 'pending_approval']);

        $this->reindex();
        CategoryTree::forgetCounts();
    }

    public function test_the_category_index_shows_real_counts_and_leaves_out_empty_categories(): void
    {
        $page = $this->get('/categories')->assertOk();

        $page->assertSee('Electronics')->assertSee('3 products')->assertSee('Laptops')->assertSee('Everything with a plug.');
        $page->assertDontSee('Empty shelf')->assertDontSee('Hidden things');
        $page->assertSee(route('categories.show', 'laptops'), false);
    }

    public function test_a_category_page_has_crumbs_subcategories_brands_and_only_its_products(): void
    {
        $page = $this->get('/categories/electronics')->assertOk()->assertViewIs('products.index');

        $page->assertSee('Everything with a plug.')->assertSee('Browse Electronics')->assertSee(route('categories.show', 'phones'), false);
        $page->assertSee('Lenovo Notebook')->assertSee('Galaxy Phone')->assertDontSee('Pending Notebook');

        $laptops = $this->get('/categories/laptops')->assertOk();
        $laptops->assertSee('Lenovo Notebook')->assertDontSee('Galaxy Phone')->assertSee('Top brands in Laptops');
        $laptops->assertSee(route('categories.show', 'electronics'), false); // the breadcrumb
        $laptops->assertSee('Lenovo');
    }

    public function test_filters_and_sorts_work_inside_a_category_and_links_stay_on_the_category(): void
    {
        $page = $this->get('/categories/laptops?brand[]=HP&sort=price_asc')->assertOk();

        $page->assertSee('HP Notebook')->assertDontSee('Lenovo Notebook');
        $page->assertSee(route('categories.show', 'laptops'), false);
        $this->assertStringNotContainsString('category=', parse_url($this->get('/categories/laptops?brand[]=HP')->getContent(), PHP_URL_QUERY) ?? '');
    }

    public function test_unknown_and_switched_off_categories_are_not_found(): void
    {
        $this->get('/categories/nope')->assertNotFound();
        $this->get('/categories/hidden-things')->assertNotFound();
    }

    public function test_a_category_with_nothing_to_buy_says_so(): void
    {
        $this->get('/categories/empty-shelf')->assertOk()->assertSee('Nothing matches that');
    }

    // --- collections ---------------------------------------------------------------------------------------------

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    public function test_a_live_manual_collection_shows_its_dates_and_only_buyable_products(): void
    {
        $this->p['ph']->update(['stock' => 2]);
        $c = app(Collections::class)->create($this->operator(), [
            'title' => 'Campus picks', 'description' => 'Things for students.', 'visibility' => 'public',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(10), 'product_ids' => [$this->p['lap1']->id, $this->p['hidden']->id, $this->p['ph']->id],
        ]);

        $page = $this->get('/collections/'.$c->slug)->assertOk();

        $page->assertSee('Campus picks')->assertSee('Things for students.')->assertSee('Runs from')->assertSee(now()->addDays(10)->format('j F Y'));
        $page->assertSee('Lenovo Notebook')->assertSee('Galaxy Phone')->assertDontSee('Pending Notebook');
        $page->assertSee('Hand-picked by the Emall team');
    }

    public function test_a_dynamic_collection_runs_its_rule_and_explains_it(): void
    {
        $c = app(Collections::class)->create($this->operator(), ['title' => 'Under R7,000', 'type' => 'dynamic', 'visibility' => 'public', 'rule' => ['price_max' => 7000, 'in_stock' => true]]);

        $page = $this->get('/collections/'.$c->slug)->assertOk();

        $page->assertSee('Made by a rule')->assertSee('priced up to R7,000')->assertSee('HP Notebook')->assertSee('Galaxy Phone')->assertDontSee('Lenovo Notebook');
        $page->assertSee('No start or end date is set');
    }

    public function test_dates_decide_what_the_public_sees(): void
    {
        $operator = $this->operator();
        $future = app(Collections::class)->create($operator, ['title' => 'Coming soon', 'visibility' => 'public', 'starts_at' => now()->addDays(3), 'product_ids' => [$this->p['lap1']->id]]);
        $ended = app(Collections::class)->create($operator, ['title' => 'Last season', 'visibility' => 'public', 'starts_at' => now()->subDays(30), 'ends_at' => now()->subDay(), 'product_ids' => [$this->p['lap1']->id]]);
        $draft = app(Collections::class)->create($operator, ['title' => 'Secret', 'visibility' => 'draft', 'product_ids' => [$this->p['lap1']->id]]);

        $this->get('/collections/'.$future->slug)->assertNotFound();
        $this->get('/collections/'.$draft->slug)->assertNotFound();
        $this->get('/collections/'.$ended->slug)->assertOk()->assertSee('This collection has ended')->assertSee('Ran from')->assertDontSee('Lenovo Notebook');
    }

    public function test_the_collections_index_lists_only_live_collections(): void
    {
        $operator = $this->operator();
        app(Collections::class)->create($operator, ['title' => 'Live one', 'visibility' => 'public', 'product_ids' => [$this->p['lap1']->id]]);
        app(Collections::class)->create($operator, ['title' => 'Ended one', 'visibility' => 'public', 'ends_at' => now()->subDay(), 'starts_at' => now()->subDays(5), 'product_ids' => [$this->p['lap1']->id]]);
        app(Collections::class)->create($operator, ['title' => 'Draft one', 'visibility' => 'draft', 'product_ids' => [$this->p['lap1']->id]]);

        $this->get('/collections')->assertOk()->assertSee('Live one')->assertDontSee('Ended one')->assertDontSee('Draft one');
        $this->assertSame(1, Collection::query()->live()->count());
    }
}
