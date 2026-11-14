<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Support\Landing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsShopFlows;
use Tests\TestCase;

/**
 * The landing (R-50): /dashboard, where a person arrives after signing in through InfoDot. One primary action per role, leading to
 * a page that answers 200 for that role; the owner's logo file, unaltered; a welcome and context from real records; an honest empty state.
 */
class LandingTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsShopFlows;
    use RefreshDatabase;

    /** The landing's own markup: from its root to the end of the page's <main>, tags removed. */
    private function landingText(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $start = strpos($html, 'data-landing=');
        $this->assertNotFalse($start, 'the landing root is on the page');
        $end = strpos($html, '</main>', $start);

        return html_entity_decode(strip_tags(substr($html, $start, $end - $start)));
    }

    /** Asserts exactly one element carries the primary-action marker and returns the address it leads to. */
    private function primaryHref(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $this->assertSame(1, preg_match_all('/\sdata-primary-action(?=[\s=>])/', $html), 'exactly one primary action');
        $this->assertSame(1, preg_match('/<a\b[^>]*\bdata-primary-action\b[^>]*>/', $html, $tag), 'the primary action is a link');
        $this->assertSame(1, preg_match('/href="([^"]+)"/', $tag[0], $href));
        $this->assertStringContainsString('ds-btn--primary', $tag[0]);
        $this->assertSame(1, substr_count($html, 'ds-btn--primary ds-btn--lg'), 'nothing else on the page is drawn as the large primary button');

        return html_entity_decode($href[1]);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_buyer_lands_on_one_primary_action_that_shops_the_mall(): void
    {
        $seller = $this->makeSeller(['name' => 'Vaal Traders']);
        $this->makeListing($seller, ['name' => 'Landing Kettle', 'price' => 349.50, 'stock' => 8]);
        $buyer = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);

        $page = $this->actingAs($buyer)->get('/dashboard')->assertOk()->assertViewIs('landing.buyer');
        $page->assertSee('<title>Welcome | Dot.Emall</title>', false)->assertSee('Welcome, Thandi.')->assertSee('Shop every store in one mall.');
        $page->assertSee('Landing Kettle')->assertSee('Vaal Traders');
        $this->assertSame(1, substr_count((string) $page->getContent(), '<h1'), 'one h1');

        $href = $this->primaryHref($page);
        $this->assertSame(route('products.index'), $href);
        $this->get($href)->assertOk()->assertSee('Landing Kettle');
    }

    public function test_an_empty_mall_is_said_plainly_and_the_first_step_is_opening_a_shop(): void
    {
        $member = User::factory()->withPersonalTeam()->create(['name' => 'Nomsa Zulu']);

        $page = $this->actingAs($member)->get('/dashboard')->assertOk()->assertViewIs('landing.buyer');
        $page->assertSee('The shelves are still empty.')->assertSee('Nothing is listed yet')->assertSee('You have not ordered anything yet.');
        $page->assertDontSee('Shop every store in one mall.')->assertDontSee('New on the shelves');

        $href = $this->primaryHref($page);
        $this->assertSame(route('sell.start'), $href);
        $this->get($href)->assertOk();

        // A brand-new member in an empty mall: there is no record to count, so the landing prints no figure at all.
        $this->assertDoesNotMatchRegularExpression('/\d/', $this->landingText($page));
    }

    public function test_a_seller_with_no_product_lands_on_adding_the_first_one(): void
    {
        $seller = $this->makeSeller(['name' => 'Busi Bakes']);

        $page = $this->actingAs($seller)->get('/dashboard')->assertOk()->assertViewIs('landing.seller');
        $page->assertSee('Welcome, '.Landing::firstName($seller).'.')->assertSee('You run Busi Bakes.')->assertSee('No products yet')->assertSee('Nothing needs you right now.');

        $href = $this->primaryHref($page);
        $this->assertSame(route('seller.products.create'), $href);
        $this->get($href)->assertOk();
        $this->assertDoesNotMatchRegularExpression('/\d/', $this->landingText($page));
    }

    public function test_a_seller_with_products_lands_on_managing_them_and_sees_only_real_figures(): void
    {
        $seller = $this->makeSeller(['name' => 'Karoo Hearth']);
        $this->makeListing($seller, ['name' => 'Cast Iron Pot', 'price' => 1249, 'stock' => 3, 'low_stock_threshold' => 5]);
        $this->makeListing($seller, ['name' => 'Waiting Whisk', 'price' => 89.90, 'stock' => 40, 'status' => 'pending_approval']);

        $page = $this->actingAs($seller)->get('/dashboard')->assertOk()->assertViewIs('landing.seller');
        $page->assertSee('Your shelf, kept honest.')->assertSee('Cast Iron Pot')->assertSee('Waiting Whisk')->assertSee('Pending review');
        $page->assertSee('1 product at or below your low-stock level')->assertSee('1 listing is waiting for the Dot.Emall team to review.');

        $href = $this->primaryHref($page);
        $this->assertSame(route('seller.products.index'), $href);
        $this->get($href)->assertOk()->assertSee('Cast Iron Pot');

        // Every figure in the landing is one of the seeded ones: two prices, one stock count of 3, one low-stock product, one pending listing.
        preg_match_all('/\d[\d,]*(?:\.\d+)?/', $this->landingText($page), $found);
        $this->assertEqualsCanonicalizing([], array_values(array_diff(array_unique($found[0]), ['1,249.00', '89.90', '3', '1'])), 'no figure that is not a seeded record');
    }

    public function test_a_buyers_orders_and_totals_come_from_their_own_records(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $other = $this->makeBuyer();
        $this->enablePaystack();
        $mine = $this->placeOrder($buyer, [[$this->makeListing($seller, ['name' => 'Mine Mug', 'price' => 120, 'stock' => 9]), 2]]);
        $theirs = $this->placeOrder($other, [[$this->makeListing($seller, ['name' => 'Their Tray', 'price' => 777, 'stock' => 9]), 1]]);

        $page = $this->actingAs($buyer)->get('/dashboard')->assertOk();
        $page->assertSee($mine->reference())->assertSee($mine->money($mine->totalCents()))->assertSee('1 order is waiting for payment.');
        $page->assertDontSee($theirs->reference());
        $this->primaryHref($page);
    }

    public function test_the_welcome_names_the_company_only_when_one_is_really_linked(): void
    {
        $member = User::factory()->withPersonalTeam()->create(['name' => 'Ayanda Mbeki']);
        $this->actingAs($member)->get('/dashboard')->assertOk()->assertSee('Welcome, Ayanda.')->assertDontSee('You are here with');

        $company = Team::forceCreate(['user_id' => $member->id, 'name' => 'Mbeki Trading', 'personal_team' => false]);
        $member->forceFill(['current_team_id' => $company->id])->save();
        $this->actingAs($member->fresh())->get('/dashboard')->assertOk()->assertSee('You are here with Mbeki Trading.');
    }

    public function test_the_logo_is_the_owners_original_file_and_the_landing_shows_it(): void
    {
        // The artwork is never redrawn or re-exported: these are the hashes of the files as the owner supplied them.
        $this->assertSame('b6314065dcbb453c721e69191617f914901c5d42ce8482ee495ef5ee1727e707', hash_file('sha256', public_path(Landing::LOGO)));
        $this->assertSame('3ab98312988e1fc96d0e3348c46e89d6f673411354503334d651f27426b0ba13', hash_file('sha256', public_path(Landing::LOGO_ON_DARK)));

        foreach ([$this->makeBuyer(), $this->makeSeller()] as $person) {
            $this->actingAs($person)->get('/dashboard')->assertOk()
                ->assertSee('src="'.asset(Landing::LOGO).'" alt="Dot.Emall"', false)
                ->assertSee('src="'.asset(Landing::LOGO_ON_DARK).'" alt="Dot.Emall"', false);
        }
    }

    public function test_the_way_back_to_infodot_follows_the_configured_infodot_host(): void
    {
        $page = $this->actingAs($this->makeBuyer())->get('/dashboard');
        $page->assertSee('<a href="https://infodot.co.za/home">InfoDot</a>', false)->assertSee('<a href="https://infodot.co.za/platforms">Platforms</a>', false);

        config(['ecosystem.infodot.redeem_url' => 'https://id.example.test:8443/api/ecosystem/redeem']);
        $this->assertSame(['home' => 'https://id.example.test:8443/home', 'platforms' => 'https://id.example.test:8443/platforms'], Landing::infodot());
        $this->actingAs($this->makeSeller())->get('/dashboard')->assertSee('<a href="https://id.example.test:8443/home">InfoDot</a>', false);
    }

    public function test_everything_the_old_doorway_led_to_is_still_one_link_away(): void
    {
        $buyer = $this->makeBuyer();
        $page = $this->actingAs($buyer)->get('/dashboard');
        foreach (['account.home', 'sell.start', 'help.index'] as $name) {
            $page->assertSee('href="'.route($name).'"', false);
            $this->get(route($name))->assertOk();
        }

        $operator = User::factory()->withPersonalTeam()->create(['is_platform_operator' => true]);
        $this->actingAs($operator)->get('/dashboard')->assertOk()->assertSee('href="'.route('operator.home').'"', false);
        $this->actingAs($buyer)->get('/dashboard')->assertDontSee('Operator desk');

        $seller = $this->makeSeller();
        $this->makeListing($seller);
        $page = $this->actingAs($seller)->get('/dashboard');
        foreach (['seller.dashboard', 'seller.products.create', 'seller.orders.index', 'account.home'] as $name) {
            $page->assertSee('href="'.route($name).'"', false);
            $this->get(route($name))->assertOk();
        }
    }

    public function test_the_platform_list_holds_only_platforms_that_exist(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance', 'forms',
            'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot'];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
    }
}
