<?php

namespace Tests\Feature\Discovery;

use App\Models\User;
use App\Services\Discovery\RecentlyViewed;
use App\Support\Actor;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class RecentlyViewedTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
    }

    public function test_a_view_is_recorded_once_per_product_and_moves_to_the_top(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Alpha Lamp', 100.0);
        $b = $this->listing($store, 'Beta Lamp', 100.0);
        $visitor = $this->visitor();
        $views = app(RecentlyViewed::class);

        $views->view($visitor, $a);
        $this->travel(1)->minutes();
        $views->view($visitor, $b);
        $this->travel(1)->minutes();
        $views->view($visitor, $a);

        $this->assertSame([$a->id, $b->id], $views->for($visitor)->pluck('id')->all());
        $this->assertSame(2, DB::table('product_views')->count());
        $this->assertSame(2, (int) DB::table('product_views')->where('product_id', $a->id)->value('view_count'));
    }

    public function test_the_list_is_trimmed_to_twenty(): void
    {
        $store = $this->store();
        $visitor = $this->visitor();
        $views = app(RecentlyViewed::class);

        foreach (range(1, 24) as $i) {
            $this->travel(1)->seconds();
            $views->view($visitor, $this->listing($store, 'Item number '.$i, 10.0));
        }

        $this->assertSame(20, DB::table('product_views')->count());
        $this->assertSame(20, $views->for($visitor, 50)->count());
        $this->assertSame('Item number 24', $views->for($visitor)->first()->name);
    }

    public function test_it_is_private_to_the_person(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Alpha Lamp', 100.0);
        app(RecentlyViewed::class)->view($this->visitor(), $a);

        $this->assertTrue(app(RecentlyViewed::class)->for($this->visitor('visitor-someone-else-xxxxxxxxxx'))->isEmpty());
    }

    public function test_the_storage_holds_no_raw_identifier(): void
    {
        $user = User::factory()->create();
        $store = $this->store();
        app(RecentlyViewed::class)->view(Actor::forUser($user), $this->listing($store, 'Alpha Lamp', 100.0));

        $row = DB::table('product_views')->first();

        $this->assertSame(32, strlen($row->actor_hash));
        $this->assertSame(Actor::hashForUser($user->id), $row->actor_hash);
    }

    public function test_things_that_cannot_be_bought_or_are_your_own_are_not_listed(): void
    {
        $seller = $this->makeSeller();
        $other = $this->store('Other Shop');
        $mine = $this->makeListing($seller, ['name' => 'My own widget']);
        $sold = $this->listing($other, 'Sold Out Widget', 10.0, ['stock' => 1]);
        $ok = $this->listing($other, 'Good Widget', 10.0);
        $actor = Actor::forUser($seller);
        $views = app(RecentlyViewed::class);

        $views->view($actor, $mine);
        $this->assertSame(0, DB::table('product_views')->count(), 'looking at your own listing is not a view');

        DB::table('product_views')->insert(['actor_hash' => $actor->hash(), 'product_id' => $mine->id, 'view_count' => 1, 'viewed_at' => now()]);
        $views->view($actor, $sold);
        $views->view($actor, $ok);
        $sold->update(['stock' => 0]);

        $this->assertSame([$ok->id], $views->for($actor)->pluck('id')->all());
    }

    public function test_a_visitors_views_follow_them_when_they_sign_in(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Alpha Lamp', 100.0);
        $b = $this->listing($store, 'Beta Lamp', 100.0);
        $user = User::factory()->create();
        $token = 'visitor-token-aaaaaaaaaaaaaaaa';
        $views = app(RecentlyViewed::class);

        $views->view(Actor::forToken($token), $a);
        $views->view(Actor::forUser($user), $a);
        $views->view(Actor::forToken($token), $b);

        $moved = $views->merge($token, $user->id);

        $this->assertSame(2, $moved);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $views->for(Actor::forUser($user))->pluck('id')->all());
        $this->assertSame(2, (int) DB::table('product_views')->where('actor_hash', Actor::hashForUser($user->id))->where('product_id', $a->id)->value('view_count'));
        $this->assertTrue($views->for(Actor::forToken($token))->isEmpty());
    }

    public function test_signing_in_through_the_app_merges_the_visitors_session_activity(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Alpha Lamp', 100.0);
        $user = User::factory()->create();

        $request = Request::create('/');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put(Actor::SESSION_KEY, 'visitor-token-aaaaaaaaaaaaaaaa');
        $this->app->instance('request', $request);
        app(RecentlyViewed::class)->view(Actor::forToken('visitor-token-aaaaaaaaaaaaaaaa'), $a);

        event(new Login('web', $user, false));

        $this->assertSame([$a->id], app(RecentlyViewed::class)->for(Actor::forUser($user))->pluck('id')->all());
    }

    public function test_views_older_than_ninety_days_are_pruned(): void
    {
        $store = $this->store();
        $a = $this->listing($store, 'Alpha Lamp', 100.0);
        app(RecentlyViewed::class)->view($this->visitor(), $a);
        DB::table('product_views')->update(['viewed_at' => now()->subDays(91)]);

        $this->artisan('emall:discovery-prune')->assertSuccessful();

        $this->assertSame(0, DB::table('product_views')->count());
    }
}
