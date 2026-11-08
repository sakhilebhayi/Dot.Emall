<?php

namespace Tests\Feature\Discovery\Pages;

use App\Models\SavedSearch;
use App\Models\User;
use App\Services\Discovery\RecentlyViewed;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

/** The assistant page, saved searches and recently viewed. */
class AssistantAndAccountPagesTest extends TestCase
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
        $this->p['lenovo'] = $this->listing($store, 'Lenovo IdeaPad 15 Laptop', 12499.00, ['category_id' => $laptops->id, 'stock' => 7], 'Lenovo');
        $this->p['hp'] = $this->listing($store, 'HP 250 Laptop', 8999.00, ['category_id' => $laptops->id, 'stock' => 4], 'HP');
        $this->p['pricey'] = $this->listing($store, 'Apple MacBook Air Laptop', 21999.00, ['category_id' => $laptops->id, 'stock' => 2], 'Apple');
        $this->reindex();
    }

    // --- assistant -----------------------------------------------------------------------------------------------

    public function test_the_empty_assistant_page_has_the_form_and_examples_that_are_links(): void
    {
        $page = $this->get('/assistant')->assertOk();

        $page->assertSee('What do you need?')->assertSee('Try one of these')->assertSee('A reliable laptop for university under R15,000')->assertDontSee('Emall answered');
    }

    public function test_an_answer_keeps_the_catalogue_facts_apart_from_our_suggestion_and_says_who_wrote_it(): void
    {
        $page = $this->get('/assistant?text='.urlencode('a reliable laptop for university under R15,000'))->assertOk();

        $page->assertSee('You asked')->assertSee('Emall answered');
        $page->assertSee('From the catalogue')->assertSee('Our suggestion');
        $page->assertSee('Written by rules, from the facts below, with no model involved');
        $page->assertSee('Lenovo IdeaPad 15 Laptop')->assertSee('HP 250 Laptop')->assertDontSee('Apple MacBook Air Laptop');
        $page->assertSee('R12,499.00');
        $page->assertSee('Up to R15,000.00')->assertSee('Remove Up to R15,000.00');
        $page->assertSee('The catalogue has no data about how well a product suits a purpose');
        $page->assertSee(e(route('search', ['category' => $this->p['lenovo']->category_id, 'price_max' => '15000', 'in_stock' => 1])), false);
    }

    public function test_removing_an_understood_part_asks_again_without_it_and_it_can_be_put_back(): void
    {
        $text = 'a laptop under R15,000';
        $page = $this->get('/assistant?'.http_build_query(['text' => $text, 'without' => ['price']]))->assertOk();

        $page->assertSee('Apple MacBook Air Laptop')->assertSee('You removed what I understood about price');
        $page->assertSee('Put back Up to R15,000.00');
        $this->assertStringContainsString('disc-chip--off', $page->getContent());

        $this->get('/assistant?'.http_build_query(['text' => $text]))->assertOk()->assertDontSee('Apple MacBook Air Laptop');
    }

    public function test_nothing_to_suggest_is_said_plainly_with_a_way_forward(): void
    {
        $this->get('/assistant?text='.urlencode('a submarine under R100'))->assertOk()
            ->assertSee('Nothing in the catalogue fits')->assertSee('only suggest products that exist')->assertSee('Browse the categories');
    }

    public function test_an_invalid_without_kind_and_over_long_text_are_validation_errors(): void
    {
        $this->get('/assistant?text=laptop&without[]=everything')->assertSessionHasErrors('without.0');
        $this->get('/assistant?text='.str_repeat('a', 401))->assertSessionHasErrors('text');
    }

    public function test_the_page_is_rate_limited_per_person_when_a_question_is_asked(): void
    {
        foreach (range(1, 8) as $i) {
            $this->get('/assistant?text=laptop')->assertOk()->assertDontSee('That was a lot of questions');
        }

        $this->get('/assistant?text=laptop')->assertOk()->assertSee('That was a lot of questions')->assertDontSee('Emall answered');
        $this->get('/assistant')->assertOk(); // the empty page never counts
        RateLimiter::clear('x');
    }

    public function test_a_model_written_suggestion_is_labelled_as_model_written(): void
    {
        config(['services.anthropic.api_key' => 'sk-ant-test-key']);
        $factory = new Factory;
        $factory->preventStrayRequests();
        $id = $this->p['hp']->id;
        $factory->fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
            'headline' => 'Two laptops fit your budget.',
            'picks' => [['product_id' => $id, 'reason' => 'At R8,999 it is the cheaper of the two, with 4 in stock.']],
            'refinements' => [],
        ])]]], 200)]);
        Http::swap($factory);

        $page = $this->get('/assistant?text='.urlencode('a laptop under R15,000'))->assertOk();

        $page->assertSee('Two laptops fit your budget.')->assertSee('Written by a language model, with every line checked against the catalogue facts before it was accepted');
        $page->assertSee('At R8,999 it is the cheaper of the two');
    }

    // --- saved searches ------------------------------------------------------------------------------------------

    public function test_saved_searches_need_a_sign_in(): void
    {
        $this->get('/account/saved-searches')->assertRedirect('/login');
        $this->post('/account/saved-searches', ['name' => 'x', 'search' => ['q' => 'laptop']])->assertRedirect('/login');
    }

    public function test_a_buyer_saves_runs_renames_changes_the_digest_and_deletes_a_search(): void
    {
        $me = User::factory()->withPersonalTeam()->create();

        $this->actingAs($me)->get('/account/saved-searches')->assertOk()->assertSee('No saved searches yet');

        $this->actingAs($me)->post(route('account.saved-searches.store'), [
            'name' => 'Cheap laptops', 'notify_frequency' => 'weekly', 'search' => ['q' => 'laptop', 'price_max' => '10000', 'brand' => ['HP']],
        ])->assertRedirect(route('account.saved-searches'))->assertSessionHas('status', 'Search saved.');

        $saved = SavedSearch::query()->where('user_id', $me->id)->firstOrFail();
        $this->assertSame('weekly', $saved->notify_frequency);
        $this->assertSame(['HP'], $saved->filters['brand']);

        $page = $this->actingAs($me)->get('/account/saved-searches')->assertOk();
        $page->assertSee('Cheap laptops')->assertSee('&quot;laptop&quot;, HP, up to R10,000', false)->assertSee('No new matches since you saved it');
        $page->assertSee(e(route('search', $saved->toRequest()->toArray())), false);

        $this->actingAs($me)->patch(route('account.saved-searches.update', $saved), ['name' => 'Work laptops'])->assertRedirect(route('account.saved-searches'));
        $this->actingAs($me)->patch(route('account.saved-searches.update', $saved), ['notify_frequency' => 'daily'])->assertRedirect();
        $saved->refresh();
        $this->assertSame(['Work laptops', 'daily'], [$saved->name, $saved->notify_frequency]);

        $this->actingAs($me)->patch(route('account.saved-searches.update', $saved), ['notify_frequency' => 'hourly'])->assertSessionHasErrors('notify_frequency');

        $this->actingAs($me)->delete(route('account.saved-searches.destroy', $saved))->assertRedirect(route('account.saved-searches'));
        $this->assertModelMissing($saved);
    }

    public function test_a_new_matching_listing_is_counted_as_new(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $this->actingAs($me)->post(route('account.saved-searches.store'), ['name' => 'Laptops', 'search' => ['q' => 'laptop', 'literal' => '1']])->assertRedirect();

        $this->listing($this->p['hp']->store, 'Brand New Gaming Laptop', 15999.00, ['category_id' => $this->p['hp']->category_id, 'stock' => 3], 'Asus');
        $this->reindex();

        $this->actingAs($me)->get('/account/saved-searches')->assertOk()->assertSee('1 new match');
    }

    public function test_nobody_reads_changes_or_deletes_someone_elses_saved_search(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        $saved = SavedSearch::factory()->create(['user_id' => $owner->id, 'name' => 'Private search', 'query' => 'laptop']);

        $this->actingAs($other)->get('/account/saved-searches')->assertOk()->assertDontSee('Private search');
        $this->actingAs($other)->patch(route('account.saved-searches.update', $saved), ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($other)->delete(route('account.saved-searches.destroy', $saved))->assertForbidden();
        $this->assertSame('Private search', $saved->fresh()->name);
    }

    public function test_saving_needs_a_name_and_something_to_save_and_the_limit_is_kept(): void
    {
        $me = User::factory()->withPersonalTeam()->create();

        $this->actingAs($me)->post(route('account.saved-searches.store'), ['name' => '', 'search' => ['q' => 'laptop']])->assertSessionHasErrors('name');
        $this->actingAs($me)->post(route('account.saved-searches.store'), ['name' => 'Nothing', 'search' => ['sort' => 'newest']])->assertSessionHasErrors('query');

        config(['discovery.wishlist.max_saved_searches' => 1]);
        $this->actingAs($me)->post(route('account.saved-searches.store'), ['name' => 'One', 'search' => ['q' => 'laptop']])->assertRedirect(route('account.saved-searches'));
        $this->actingAs($me)->post(route('account.saved-searches.store'), ['name' => 'Two', 'search' => ['q' => 'hp']])->assertSessionHasErrors('name');
    }

    // --- recently viewed -----------------------------------------------------------------------------------------

    public function test_recently_viewed_is_a_visitors_own_list_kept_by_session_and_can_be_cleared(): void
    {
        $token = str_repeat('v', 40);
        $views = app(RecentlyViewed::class);
        $views->view(Actor::forToken($token), $this->p['hp']);

        $this->get('/recently-viewed')->assertOk()->assertSee('Nothing viewed yet')->assertDontSee('HP 250 Laptop');

        $this->withSession([Actor::SESSION_KEY => $token])->get('/recently-viewed')->assertOk()->assertSee('HP 250 Laptop')->assertSee('moves to your account if you sign in');

        $this->withSession([Actor::SESSION_KEY => $token])->delete('/recently-viewed')->assertRedirect('/recently-viewed');
        $this->withSession([Actor::SESSION_KEY => $token])->get('/recently-viewed')->assertOk()->assertSee('Nothing viewed yet');
    }

    public function test_a_signed_in_buyer_sees_only_their_own_views(): void
    {
        $me = User::factory()->withPersonalTeam()->create();
        $other = User::factory()->withPersonalTeam()->create();
        app(RecentlyViewed::class)->view(Actor::forUser($me), $this->p['lenovo']);
        app(RecentlyViewed::class)->view(Actor::forUser($other), $this->p['pricey']);

        $this->actingAs($me)->get('/recently-viewed')->assertOk()->assertSee('Lenovo IdeaPad 15 Laptop')->assertDontSee('Apple MacBook Air Laptop')->assertSee('follows your account');
    }
}
