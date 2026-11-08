<?php

namespace Tests\Feature\Discovery;

use App\Models\User;
use App\Services\Discovery\SearchLog;
use App\Services\Search\SearchRequest;
use App\Services\Search\SearchService;
use App\Services\Search\Suggest;
use App\Services\Search\Text;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\TestCase;

class SuggestAndLogTest extends TestCase
{
    use BuildsCatalogue;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();

        $store = $this->store();
        $tvs = $this->category('Televisions');
        $this->listing($store, 'Samsung 55 Inch Smart TV', 11999.0, ['category_id' => $tvs->id], 'Samsung');
        $this->listing($store, 'Samsung Galaxy A54', 6499.0, [], 'Samsung');
        $this->listing($store, 'Sandals Leather', 399.0);
        $this->reindex();
    }

    private function search(string $q, Actor $actor): void
    {
        app(SearchService::class)->search(new SearchRequest(text: $q), $actor);
    }

    public function test_suggestions_come_from_real_products_categories_and_brands(): void
    {
        $result = app(Suggest::class)->for('sam');

        $this->assertSame(['Samsung 55 Inch Smart TV', 'Samsung Galaxy A54'], collect($result['products'])->pluck('title')->sort()->values()->all());
        $this->assertSame([['name' => 'Samsung', 'count' => 2]], $result['brands']);
        $this->assertSame([], $result['categories']);

        $tv = app(Suggest::class)->for('televi');
        $this->assertSame('Televisions', $tv['categories'][0]['name']);
        $this->assertSame(1, $tv['categories'][0]['count']);
    }

    public function test_a_suggestion_carries_the_price_and_an_image_placeholder_id(): void
    {
        $first = app(Suggest::class)->for('galaxy')['products'][0];

        $this->assertSame('6499.00', $first['price']);
        $this->assertArrayHasKey('image_id', $first);
        $this->assertTrue($first['in_stock']);
    }

    public function test_recent_searches_are_the_persons_own_and_private(): void
    {
        $me = $this->visitor('visitor-me-aaaaaaaaaaaaaaaaaaaa');
        $other = $this->visitor('visitor-other-bbbbbbbbbbbbbbbbb');

        $this->search('galaxy', $me);
        $this->search('sandals', $other);

        $this->assertSame(['galaxy'], app(Suggest::class)->for('', $me)['recent']);
        $this->assertSame(['sandal'], collect(app(Suggest::class)->for('', $other)['recent'])->map(fn ($q) => Text::index($q))->all());
        $this->assertSame([], app(Suggest::class)->for('', $this->visitor('visitor-new-cccccccccccccccccc'))['recent']);
    }

    public function test_popular_searches_need_several_different_people(): void
    {
        foreach (['a', 'b'] as $i => $letter) {
            $this->search('samsung', $this->visitor("visitor-{$letter}-".str_repeat($letter, 20)));
        }

        $this->assertSame([], app(Suggest::class)->for('')['popular'], 'two people are below the minimum of three');

        $this->search('samsung', $this->visitor('visitor-c-'.str_repeat('c', 20)));

        $this->assertSame([['query' => 'samsung', 'people' => 3]], app(Suggest::class)->for('')['popular']);
        // One person repeating a search never makes it popular.
        for ($i = 0; $i < 5; $i++) {
            DB::table('search_queries')->insert(['query' => 'repeat', 'result_count' => 3, 'actor_hash' => 'one-person', 'created_at' => now()]);
        }
        $this->assertNotContains('repeat', array_column(app(Suggest::class)->for('')['popular'], 'query'));
    }

    public function test_searches_that_found_nothing_are_not_popular_but_are_reported_to_the_operator(): void
    {
        foreach (['a', 'b', 'c'] as $letter) {
            $this->search('zzyzx', $this->visitor("visitor-{$letter}-".str_repeat($letter, 20)));
        }

        $this->assertSame([], app(Suggest::class)->for('')['popular']);

        $report = app(SearchLog::class)->zeroResults();
        $this->assertSame('zzyzx', $report[0]['query']);
        $this->assertSame(3, $report[0]['people']);
    }

    public function test_the_log_stores_a_hash_and_never_personal_data(): void
    {
        $user = User::factory()->create();
        $this->search('galaxy', Actor::forUser($user));
        $this->search('jane.doe@example.com', Actor::forUser($user));
        $this->search('0821234567', Actor::forUser($user));

        $row = DB::table('search_queries')->first();

        $this->assertSame(1, DB::table('search_queries')->count(), 'an e-mail address and a phone number are not stored');
        $this->assertSame(32, strlen($row->actor_hash));
        $this->assertNotEquals((string) $user->id, $row->actor_hash);
        $this->assertSame(['id', 'query', 'result_count', 'actor_hash', 'created_at'], array_keys((array) $row));
    }

    public function test_a_repeat_within_two_minutes_counts_once_and_paging_is_not_logged(): void
    {
        $me = $this->visitor('visitor-me-aaaaaaaaaaaaaaaaaaaa');
        $this->search('galaxy', $me);
        $this->search('galaxy', $me);
        app(SearchService::class)->search(new SearchRequest(text: 'galaxy', page: 2), $me);

        $this->assertSame(1, DB::table('search_queries')->count());
    }

    public function test_old_searches_are_pruned(): void
    {
        $this->search('galaxy', $this->visitor());
        DB::table('search_queries')->update(['created_at' => now()->subDays(91)]);
        $this->search('sandals', $this->visitor('visitor-other-bbbbbbbbbbbbbbbbb'));

        $this->artisan('emall:discovery-prune')->assertSuccessful();

        $this->assertSame(['sandal'], DB::table('search_queries')->pluck('query')->map(fn ($q) => Text::index($q))->all());
    }

    public function test_the_suggest_endpoint_is_public_json_and_rate_limited(): void
    {
        $this->getJson(route('search.suggest', ['q' => 'galaxy']))
            ->assertOk()
            ->assertJsonPath('products.0.title', 'Samsung Galaxy A54')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->getJson(route('search.suggest', ['q' => str_repeat('x', 61)]))->assertStatus(422);

        for ($i = 0; $i < 95; $i++) {
            $status = $this->getJson(route('search.suggest', ['q' => 'g']))->status();
        }

        $this->assertSame(429, $status);
    }
}
