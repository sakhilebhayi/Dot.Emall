<?php

namespace Tests\Feature\Integration;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The routes file carries four marked blocks from four workstreams (data-a, data-b, integration, dev). They must all be
 * there, none may shadow another, and the integration aliases must be what they claim to be: the same page under the name
 * the shell navigation asks for, with the same access rules as the page they alias.
 */
class RouteBlocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_block_registered_its_routes(): void
    {
        foreach (['guest.orders.access', 'guest.orders.payment.callback', 'search.suggest', 'assistant.ask', 'review-photos.show', 'search', 'account.home', 'seller.dashboard', 'sell.start', 'dev.login', 'dev.logout', 'dev.kit', 'products.index', 'products.show', 'dashboard', 'stores.index', 'stores.show', 'stores.message', 'wishlist.index', 'wishlist.toggle', 'wishlist.shared', 'account.alerts', 'products.cart', 'reviews.vote', 'questions.store'] as $name) {
            $this->assertTrue(Route::has($name), "route {$name} is not registered");
        }
    }

    public function test_the_literal_search_paths_are_not_swallowed_by_a_parameter_route(): void
    {
        $this->assertSame('/search', parse_url(route('search'), PHP_URL_PATH));
        $this->assertSame('/search/suggest', parse_url(route('search.suggest'), PHP_URL_PATH));
        $this->get('/search/suggest?q=ab')->assertOk()->assertJsonStructure(['products', 'categories']);
        $this->get('/search?q=nothing-matches-this')->assertOk()->assertSee('Nothing matches that');
    }

    public function test_the_aliases_open_the_page_they_alias_for_the_people_it_is_for(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->get('/sell/dashboard')->assertRedirect('/login');
        // The real onboarding page (W6) is public: a visitor reads the terms before signing in.
        $this->get('/sell/start')->assertOk()->assertSee('Sign in with InfoDot');

        $seller = User::factory()->withPersonalTeam()->create();
        Store::factory()->create(['owner_id' => $seller->id, 'name' => 'Alias Stores']);

        $this->actingAs($seller->fresh());
        // /dashboard is a doorway: a seller goes to the seller desk, and /account is the buyer's overview (W5), not the seller desk.
        $this->get('/dashboard')->assertRedirect(route('seller.dashboard'));
        $this->get('/sell/dashboard')->assertOk()->assertSee('Seller desk')->assertSee('Alias Stores');
        $this->get('/account')->assertOk()->assertSee('<title>Your account | Dot.Emall</title>', false)->assertSee('Open your seller desk');
        // A seller who already has a store reads the same public page, and it says so and points at their store (W6 made it public for everyone).
        $this->get('/sell/start')->assertOk()->assertSee('You already have a store')->assertSee('Alias Stores is on Emall')->assertSee(route('seller.store.status'), false);

        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/sell/start')->assertOk();
    }

    public function test_the_shell_navigation_resolves_the_aliases_instead_of_leaving_pending_links(): void
    {
        $buyer = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($buyer)->get('/account')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-pending-route="account.home"', $html);
        $this->assertStringNotContainsString('data-pending-route="search"', $html);
        $this->assertStringContainsString(route('account.home'), $html);
    }
}
