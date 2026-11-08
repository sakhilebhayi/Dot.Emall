<?php

namespace Tests\Feature\Discovery\Pages;

use App\Models\User;
use App\Services\Catalogue\CategoryTree;
use App\Services\Discovery\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** The search page (/search and /products are one controller): filters, sorts, chips, zero results, pagination, URL state, tracking. */
class SearchPageTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();

        $store = $this->store('Vaal Traders');
        $laptops = $this->category('Laptops');
        $tvs = $this->category('Televisions');

        $this->p['lenovo'] = $this->listing($store, 'Lenovo IdeaPad Slim 3 Notebook', 8999.00, ['category_id' => $laptops->id, 'stock' => 8], 'Lenovo');
        $this->p['hp'] = $this->listing($store, 'HP 250 Business Notebook', 6499.00, ['category_id' => $laptops->id, 'stock' => 3], 'HP');
        $this->p['dell'] = $this->listing($store, 'Dell Inspiron Notebook', 10499.00, ['category_id' => $laptops->id, 'stock' => 0], 'Dell');
        $this->p['tv'] = $this->listing($store, 'Samsung 55 Inch Smart TV', 11999.00, ['category_id' => $tvs->id, 'stock' => 5], 'Samsung');
        $this->catalogue->attribute($this->p['tv']->id, 'screen_size', 'Screen size', '55 inch', 55.0, 'inch');
        $this->catalogue->storeRegions[$store->id] = 'Gauteng';

        $this->reindex();
        CategoryTree::forgetCounts();
    }

    public function test_search_and_products_are_the_same_page_for_a_guest(): void
    {
        $a = $this->get('/search?q=notebook')->assertOk()->assertViewIs('products.index');
        $b = $this->get('/products?q=notebook')->assertOk()->assertViewIs('products.index');

        foreach ([$a, $b] as $page) {
            $page->assertSee('Lenovo IdeaPad Slim 3 Notebook')->assertSee('HP 250 Business Notebook')->assertDontSee('Samsung 55 Inch Smart TV');
        }
    }

    public function test_the_page_with_no_words_lists_everything_buyable_and_says_how_it_is_ordered(): void
    {
        $this->get('/products')->assertOk()->assertSee('4 products')->assertSee('No search words');
    }

    public function test_words_that_all_became_filters_are_not_reported_as_no_search_words(): void
    {
        $page = $this->get('/search?q=laptops')->assertOk();

        $page->assertSee('Your words became the filters shown above')->assertDontSee('No search words');
    }

    public function test_a_brand_filter_narrows_the_results_and_shows_a_removable_chip(): void
    {
        $page = $this->get('/search?q=notebook&brand[]=HP')->assertOk();

        $page->assertSee('HP 250 Business Notebook')->assertDontSee('Lenovo IdeaPad Slim 3 Notebook');
        $page->assertSee('Remove filter HP');
        $page->assertSee('href="'.url('/search').'?q=notebook"', false);
    }

    public function test_sorting_by_price_changes_the_order_and_is_kept_in_the_url(): void
    {
        $asc = $this->get('/search?q=notebook&sort=price_asc')->assertOk()->getContent();
        $desc = $this->get('/search?q=notebook&sort=price_desc')->assertOk()->getContent();

        $this->assertLessThan(strpos($asc, 'Lenovo IdeaPad'), strpos($asc, 'HP 250'));
        $this->assertLessThan(strpos($desc, 'HP 250'), strpos($desc, 'Lenovo IdeaPad'));
        $this->assertStringContainsString('<option value="price_asc" selected>', $asc);
    }

    public function test_an_inferred_filter_is_a_chip_that_says_so_and_can_be_turned_off(): void
    {
        $page = $this->get('/search?q=laptops')->assertOk();

        $page->assertSee('from your words');
        $page->assertSee('Search the words only, without Laptops');
        $page->assertSee('literal=1', false);
        $page->assertDontSee('name="category"', false); // the category facet is left out while the words named the category
    }

    public function test_price_range_and_availability_filters(): void
    {
        $page = $this->get('/search?q=notebook&price_min=7000&price_max=9500')->assertOk();
        $page->assertSee('Lenovo IdeaPad Slim 3 Notebook')->assertDontSee('HP 250 Business Notebook');

        $stock = $this->get('/search?q=notebook&in_stock=1')->assertOk();
        $stock->assertDontSee('Dell Inspiron Notebook');
    }

    public function test_a_search_with_no_match_is_honest_and_offers_a_way_forward(): void
    {
        $page = $this->get('/search?q=zzzzqqq')->assertOk();

        $page->assertSee('Nothing matches that')->assertSee('No listing contains what you typed')->assertSee('Describe it to Emall')->assertSee('Browse a category');
        $page->assertSee(route('assistant'), false);
    }

    public function test_a_filter_combination_with_no_match_offers_to_drop_one_filter_with_the_count(): void
    {
        $page = $this->get('/search?q=notebook&price_max=100')->assertOk();

        $page->assertSee('Nothing matches that');
        $this->assertStringContainsString('to see', $page->getContent());
    }

    public function test_popular_searches_on_the_empty_page_are_real(): void
    {
        foreach (range(1, 3) as $i) {
            DB::table('search_queries')->insert(['query' => 'notebook bag', 'result_count' => 4, 'actor_hash' => 'actor-'.$i, 'created_at' => now()]);
        }

        $this->get('/search?q=zzzzqqq')->assertOk()->assertSee('notebook bag');
    }

    public function test_pagination_carries_the_search_and_a_page_past_the_end_shows_the_last_page(): void
    {
        $store = $this->store('Bulk Store');

        foreach (range(1, 30) as $i) {
            $this->listing($store, 'Pagination Widget '.$i, 10.00 + $i, ['stock' => 5]);
        }

        $this->reindex();

        $first = $this->get('/search?q=widget&sort=price_asc')->assertOk();
        $first->assertSee('Showing 1 to 24 of 30');
        $first->assertSee('page=2', false);
        $this->assertStringContainsString('sort=price_asc', $first->getContent());

        $this->get('/search?q=widget&sort=price_asc&page=2')->assertOk()->assertSee('Showing 25 to 30 of 30');
        $this->get('/search?q=widget&page=99')->assertOk()->assertSee('Showing 25 to 30 of 30');
    }

    public function test_the_list_view_draws_rows(): void
    {
        $this->get('/search?q=notebook&view=list')->assertOk()->assertSee('ds-pcard--row', false);
        $this->get('/search?q=notebook')->assertOk()->assertDontSee('ds-pcard--row', false);
    }

    public function test_a_search_is_recorded_once_on_the_first_page_only(): void
    {
        $this->get('/search?q=notebook')->assertOk();
        $this->assertSame(1, DB::table('search_queries')->where('query', 'notebook')->count());
        $this->assertSame(1, DB::table('commerce_events')->where('name', Track::SEARCH)->count());

        $this->get('/search?q=notebook&page=2')->assertOk();
        $this->assertSame(1, DB::table('search_queries')->where('query', 'notebook')->count());
    }

    public function test_a_nonsense_query_string_never_breaks_the_page(): void
    {
        $this->get('/search?attr[a][b][c]=1&brand[x][y]=1&price_min[]=1&sort[]=1&q[]=1')->assertStatus(302); // q must be a string
        $this->get('/search?attr[a][b][c]=1&brand[x][y]=1&sort=nonsense&page=abc')->assertOk();
    }

    public function test_the_search_words_are_escaped_everywhere(): void
    {
        $page = $this->get('/search?q='.urlencode('<script>alert(1)</script> & "x"'))->assertOk();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $page->getContent());
        $this->assertStringNotContainsString('&amp;amp;', $page->getContent());
    }

    public function test_an_unknown_category_is_a_validation_error_and_a_slug_works(): void
    {
        $this->get('/search?category=999999')->assertSessionHasErrors('category');
        $this->get('/search?category=laptops')->assertOk()->assertSee('Lenovo IdeaPad Slim 3 Notebook')->assertDontSee('Samsung 55 Inch Smart TV');
    }

    public function test_save_this_search_asks_a_guest_to_sign_in_and_gives_a_buyer_the_form(): void
    {
        $this->get('/search?q=notebook')->assertOk()->assertSee('Sign in with InfoDot')->assertDontSee('account/saved-searches', false);

        $buyer = User::factory()->withPersonalTeam()->create();
        $this->actingAs($buyer)->get('/search?q=notebook')->assertOk()->assertSee(route('account.saved-searches.store'), false)->assertSee('E-mail me new matches');
    }

    public function test_the_region_the_shopper_chose_offers_sellers_from_there(): void
    {
        $this->withSession(['shopper.region' => 'Gauteng'])->get('/search?q=notebook')->assertOk()->assertSee('Only sellers in Gauteng')->assertSee('Ships from your province')->assertSee('Delivering to');
    }

    public function test_the_page_runs_a_bounded_number_of_queries(): void
    {
        DB::enableQueryLog();
        $this->get('/search?q=notebook')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(70, $count, "the search page ran {$count} queries");
    }
}
