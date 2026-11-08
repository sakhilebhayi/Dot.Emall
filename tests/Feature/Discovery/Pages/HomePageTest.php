<?php

namespace Tests\Feature\Discovery\Pages;

use App\Models\User;
use App\Services\Catalogue\CategoryTree;
use App\Services\Discovery\Collections;
use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\RecommendationSet;
use App\Services\Discovery\RecommendedItem;
use App\Services\Discovery\Track;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
    }

    public function test_an_empty_marketplace_shows_the_honest_home_and_omits_every_section_with_nothing_in_it(): void
    {
        $page = $this->get('/')->assertOk();

        $page->assertSee('Shop with the total in sight')->assertSee('Ask Emall');
        $page->assertDontSee('id="categories"', false);
        foreach (['New arrivals', 'Trending now', 'Collections', 'Stores with a track record', 'Continue where you left off', 'Buy again', 'Popular searches'] as $absent) {
            $page->assertDontSee($absent);
        }
        $page->assertSee('Sell on Emall');
    }

    public function test_the_explainer_label_of_an_empty_shop_stays_in_the_flow_instead_of_hanging_off_the_page(): void
    {
        // No listing to photograph: the hero shows the explainer label on its own...
        $this->get('/')->assertOk()->assertSee('ds-hero__label--solo', false);

        // ...and on wide screens the rule that lays the label over a photograph must not apply to it (it hung off the left edge, over the next band).
        $css = file_get_contents(resource_path('css/welcome.css'));
        $this->assertMatchesRegularExpression('/\.ds-hero__label\.ds-hero__label--solo\s*\{[^}]*position:\s*static/', $css);
    }

    public function test_the_sell_path_goes_to_sell_start(): void
    {
        // The home page no longer carries its own sell band (R-43 polish #23): the header's "Sell on Emall" link and the footer column lead to the same page.
        $this->get('/')->assertOk()->assertSee(route('sell.start'), false)->assertSee('Sell on Emall')->assertDontSee('Start selling');
    }

    public function test_a_guest_gets_discovery_with_no_sign_in_and_every_block_says_where_it_comes_from(): void
    {
        $store = $this->store('Vaal Traders');
        $cat = $this->category('Kitchen');
        $a = $this->listing($store, 'Air Fryer 4L', 1299.00, ['category_id' => $cat->id, 'stock' => 6]);
        $this->listing($store, 'Kettle', 399.00, ['category_id' => $cat->id, 'stock' => 6]);
        $this->reindex();
        CategoryTree::forgetCounts();

        foreach (range(1, 5) as $i) {
            app(Track::class)->productView($this->visitor("visitor-{$i}-".str_repeat((string) $i, 20)), $a);
        }

        $page = $this->get('/')->assertOk();

        $page->assertSee('id="categories"', false)->assertSee('2 products you can buy today')->assertSee(route('categories.show', 'kitchen'), false);
        $page->assertSee('New arrivals')->assertSee('Buyable products listed in the last 30 days, newest first.');
        $page->assertSee('Trending now')->assertSee('5 views and 0 sold in the last 7 days');
        $page->assertDontSee('Continue where you left off');
    }

    public function test_a_returning_shopper_sees_where_they_left_off_and_only_they_do(): void
    {
        $store = $this->store('Vaal Traders');
        $a = $this->listing($store, 'Air Fryer 4L', 1299.00, ['stock' => 6]);
        $this->reindex();
        $me = User::factory()->withPersonalTeam()->create();
        app(RecentlyViewed::class)->view(Actor::forUser($me), $a);

        $this->actingAs($me)->get('/')->assertOk()->assertSee('Continue where you left off')->assertSee('You viewed this');

        auth()->logout();
        $this->get('/')->assertOk()->assertDontSee('Continue where you left off');
    }

    public function test_collections_appear_on_the_home_page_with_their_dates(): void
    {
        $store = $this->store('Vaal Traders');
        $a = $this->listing($store, 'Air Fryer 4L', 1299.00, ['stock' => 6]);
        app(Collections::class)->create(User::factory()->create(['is_platform_operator' => true]), ['title' => 'Kitchen winners', 'visibility' => 'public', 'ends_at' => now()->addDays(5), 'product_ids' => [$a->id]]);

        $this->get('/')->assertOk()->assertSee('Kitchen winners')->assertSee('Ends on')->assertSee(route('collections.show', 'kitchen-winners'), false);
    }

    public function test_the_province_chosen_feeds_local_sellers_and_is_remembered(): void
    {
        $store = $this->store('Vaal Traders');
        $this->listing($store, 'Air Fryer 4L', 1299.00, ['stock' => 6]);
        $this->catalogue->storeRegions[$store->id] = 'Gauteng';
        $this->reindex();

        $this->get('/')->assertOk()->assertDontSee('Sellers in Gauteng')->assertSee('Choose your province')->assertDontSee('See sellers near you');

        $this->from('/')->post(route('region.update'), ['province' => 'Gauteng', 'return' => '/'])->assertRedirect('/')->assertSessionHas('shopper.region', 'Gauteng')->assertCookie('emall_region');

        $page = $this->get('/')->assertOk();
        $page->assertSee('Sellers in Gauteng')->assertSee('Delivering to')->assertDontSee('Choose your province');
    }

    public function test_the_region_form_accepts_only_a_province_and_a_local_return_path(): void
    {
        $this->post(route('region.update'), ['province' => 'Narnia', 'return' => 'https://evil.example/'])->assertRedirect(url('/'))->assertSessionMissing('shopper.region');
        $this->post(route('region.update'), ['province' => 'western cape', 'return' => '//evil.example'])->assertRedirect(url('/'))->assertSessionHas('shopper.region', 'Western Cape');
        $this->post(route('region.update'), ['province' => '', 'return' => '/search'])->assertRedirect('/search')->assertSessionMissing('shopper.region');
    }

    public function test_the_rail_prints_its_title_its_basis_and_the_real_number_on_each_item(): void
    {
        $store = $this->store('Vaal Traders');
        $a = $this->listing($store, 'Tent 4P', 2599.00, ['stock' => 3]);
        $b = $this->listing($store, 'Sleeping bag', 799.00, ['stock' => 3]);
        $a->load(['store', 'images']);
        $b->load(['store', 'images']);

        $set = new RecommendationSet('bought_together', 'Bought together', 'Counted from paid orders that held this product and the one shown. Shown only when at least 3 orders agree.', [new RecommendedItem($b, 'Bought together in 5 orders', 5)]);

        $html = Blade::render('<x-disc-rail :set="$set" level="3" />', ['set' => $set]);

        $this->assertMatchesRegularExpression('/<h3 class="disc-rail__title"\s+id="rail-bought_together-h"\s*>Bought together<\/h3>/', $html);
        $this->assertStringContainsString('Counted from paid orders', $html);
        $this->assertStringContainsString('Bought together in 5 orders', $html);
        $this->assertStringContainsString(route('products.show', $b), $html);
        $this->assertSame('', trim(Blade::render('<x-disc-rail :items="[]" title="Nothing" />')), 'a rail with no items renders nothing');
    }

    public function test_the_home_page_runs_a_bounded_number_of_queries(): void
    {
        $store = $this->store('Vaal Traders');
        $cat = $this->category('Kitchen');
        foreach (range(1, 12) as $i) {
            $this->listing($store, 'Item '.$i, 100 + $i, ['category_id' => $cat->id, 'stock' => 5]);
        }
        $this->reindex();
        CategoryTree::forgetCounts();

        DB::enableQueryLog();
        $this->get('/')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(60, $count, "the home page ran {$count} queries");
    }

    public function test_the_suggest_endpoint_feeds_the_header_search_with_one_flat_list_ending_in_ask_emall(): void
    {
        $store = $this->store('Vaal Traders');
        $this->listing($store, 'Air Fryer 4L', 1299.00, ['stock' => 6]);
        $this->reindex();

        $json = $this->getJson('/search/suggest?q=air')->assertOk()->json();

        $this->assertSame('Air Fryer 4L', $json['suggestions'][0]['label']);
        $last = end($json['suggestions']);
        $this->assertSame('Ask Emall: air', $last['label']);
        $this->assertSame(route('assistant', ['text' => 'air']), $last['url']);
    }
}
