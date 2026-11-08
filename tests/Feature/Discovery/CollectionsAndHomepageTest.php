<?php

namespace Tests\Feature\Discovery;

use App\Models\Collection;
use App\Models\User;
use App\Services\Discovery\Collections;
use App\Services\Discovery\Homepage;
use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\Track;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCatalogue;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPaidOrders;
use Tests\TestCase;

class CollectionsAndHomepageTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsMarketplace;
    use BuildsPaidOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
    }

    private function operator(): User
    {
        return User::factory()->create(['is_platform_operator' => true]);
    }

    private function keys(array $sections): array
    {
        return array_map(fn ($s) => $s->key, $sections);
    }

    // --- collections --------------------------------------------------------------------------------------------

    public function test_only_the_operator_curates_collections(): void
    {
        $this->expectException(ValidationException::class);
        app(Collections::class)->create($this->makeBuyer(), ['title' => 'Winter warmers']);
    }

    public function test_a_manual_collection_keeps_the_curators_order_and_hides_what_cannot_be_bought(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['name' => 'Wool blanket']);
        $b = $this->makeListing($seller, ['name' => 'Hot water bottle']);
        $c = $this->makeListing($seller, ['name' => 'Fleece throw']);
        $collection = app(Collections::class)->create($this->operator(), ['title' => 'Winter warmers', 'visibility' => 'public', 'product_ids' => [$b->id, $c->id, $a->id]]);

        $c->update(['is_active' => false]);

        $this->assertSame('winter-warmers', $collection->slug);
        $this->assertSame([$b->id, $a->id], app(Collections::class)->products($collection)->pluck('id')->all());
    }

    public function test_a_dynamic_collection_is_a_rule_resolved_live(): void
    {
        $seller = $this->makeSeller();
        $kitchen = $this->category('Kitchen');
        $cheap = $this->makeListing($seller, ['name' => 'Wooden spoon', 'category_id' => $kitchen->id, 'price' => 40]);
        $this->makeListing($seller, ['name' => 'Copper pot', 'category_id' => $kitchen->id, 'price' => 900]);
        $this->reindex();

        $collection = app(Collections::class)->create($this->operator(), [
            'title' => 'Kitchen under R100', 'type' => 'dynamic', 'visibility' => 'public',
            'rule' => ['category' => 'kitchen', 'price_max' => 100],
        ]);

        $this->assertSame([$cheap->id], app(Collections::class)->products($collection)->pluck('id')->all());

        $new = $this->makeListing($seller, ['name' => 'Tea strainer', 'category_id' => $kitchen->id, 'price' => 30]);
        $this->reindex();

        $this->assertEqualsCanonicalizing([$cheap->id, $new->id], app(Collections::class)->products($collection)->pluck('id')->all());
    }

    public function test_rules_are_validated(): void
    {
        $operator = $this->operator();

        foreach ([
            ['title' => 'Empty rule', 'type' => 'dynamic', 'rule' => []],
            ['title' => 'Unknown category', 'type' => 'dynamic', 'rule' => ['category' => 'nope']],
            ['title' => 'Bad sort', 'type' => 'dynamic', 'rule' => ['price_max' => 5, 'sort' => 'random']],
            ['title' => 'Backwards dates', 'starts_at' => '2026-12-31', 'ends_at' => '2026-12-01'],
            ['title' => ''],
        ] as $data) {
            try {
                app(Collections::class)->create($operator, $data);
                $this->fail('accepted '.json_encode($data));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, Collection::count());
    }

    public function test_a_collection_is_live_only_inside_its_dates_and_when_public(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $operator = $this->operator();
        $service = app(Collections::class);

        $service->create($operator, ['title' => 'Draft', 'product_ids' => [$product->id]]);
        $service->create($operator, ['title' => 'Future', 'visibility' => 'public', 'starts_at' => now()->addDay(), 'product_ids' => [$product->id]]);
        $service->create($operator, ['title' => 'Ended', 'visibility' => 'public', 'ends_at' => now()->subDay(), 'product_ids' => [$product->id]]);
        $service->create($operator, ['title' => 'Now', 'visibility' => 'public', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'product_ids' => [$product->id]]);
        $service->create($operator, ['title' => 'Empty public', 'visibility' => 'public']);

        $this->assertSame(['Now'], $service->live()->map(fn ($row) => $row['collection']->title)->all());
    }

    // --- homepage -----------------------------------------------------------------------------------------------

    public function test_an_empty_marketplace_has_no_sections_at_all(): void
    {
        $this->assertSame([], app(Homepage::class)->for($this->visitor()));
    }

    public function test_new_arrivals_use_listing_dates_and_say_so(): void
    {
        $seller = $this->makeSeller();
        $new = $this->makeListing($seller, ['name' => 'Fresh arrival']);
        $old = $this->makeListing($seller, ['name' => 'Old stock']);
        DB::table('products')->where('id', $old->id)->update(['created_at' => now()->subDays(60)]);

        $sections = app(Homepage::class)->for($this->visitor());
        $section = collect($sections)->firstWhere('key', 'new_arrivals');

        $this->assertSame([$new->id], array_map(fn ($i) => $i->product->id, $section->items));
        $this->assertStringContainsString('last 30 days', $section->basis);
        $this->assertSame(['new_arrivals'], $this->keys($sections));
    }

    public function test_trending_needs_real_events_and_shows_the_numbers(): void
    {
        $seller = $this->makeSeller();
        $hot = $this->makeListing($seller, ['name' => 'Hot item']);
        $cold = $this->makeListing($seller, ['name' => 'Cold item']);
        $track = app(Track::class);

        foreach (range(1, 4) as $i) {
            $track->productView($this->visitor("visitor-{$i}-".str_repeat((string) $i, 20)), $hot);
        }
        $this->assertNull(collect(app(Homepage::class)->for($this->visitor()))->firstWhere('key', 'trending'), '4 events is below the minimum of 5');

        $this->paidOrder($this->makeBuyer(), [$hot]);
        $track->productView($this->visitor(), $cold);

        $section = collect(app(Homepage::class)->for($this->visitor()))->firstWhere('key', 'trending');

        $this->assertSame([$hot->id], array_map(fn ($i) => $i->product->id, $section->items));
        $this->assertSame('4 views and 1 sold in the last 7 days', $section->items[0]->basis);
    }

    public function test_popular_categories_come_from_real_counts(): void
    {
        $seller = $this->makeSeller();
        $phones = $this->category('Phones');
        $product = $this->makeListing($seller, ['category_id' => $phones->id]);
        $track = app(Track::class);

        foreach (range(1, 5) as $i) {
            $track->productView($this->visitor("visitor-{$i}-".str_repeat((string) $i, 20)), $product);
        }

        $section = collect(app(Homepage::class)->for($this->visitor()))->firstWhere('key', 'categories');

        $this->assertSame('Phones', $section->items[0]['name']);
        $this->assertSame('5 views and 0 units sold in the last 30 days', $section->items[0]['basis']);
    }

    public function test_featured_stores_need_completed_orders_and_show_ratings_only_when_shown(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 50]);

        foreach (range(1, 2) as $i) {
            $this->paidOrder($this->makeBuyer(), [$product]);
        }
        $this->assertNull(collect(app(Homepage::class)->for($this->visitor()))->firstWhere('key', 'stores'));

        $this->paidOrder($this->makeBuyer(), [$product]);
        $section = collect(app(Homepage::class)->for($this->visitor()))->firstWhere('key', 'stores');

        $this->assertSame('3 orders in the last 90 days', $section->items[0]['basis']);
        $this->assertNull($section->items[0]['rating']);
    }

    public function test_personal_sections_reflect_the_persons_own_history_only(): void
    {
        $seller = $this->makeSeller();
        $phones = $this->category('Phones');
        $a = $this->makeListing($seller, ['name' => 'Phone A', 'category_id' => $phones->id]);
        $b = $this->makeListing($seller, ['name' => 'Phone B', 'category_id' => $phones->id]);
        $c = $this->makeListing($seller, ['name' => 'Phone C', 'category_id' => $phones->id]);
        $me = $this->visitor();
        $views = app(RecentlyViewed::class);
        $views->view($me, $a);
        $views->view($me, $b);

        $sections = app(Homepage::class)->for($me);
        $keys = $this->keys($sections);

        $this->assertContains('continue', $keys);
        $interests = collect($sections)->firstWhere('key', 'interests');
        $this->assertSame([$c->id], array_map(fn ($i) => $i->product->id, $interests->items), 'products already viewed are left out');
        $this->assertStringContainsString('Phones (2 times)', $interests->basis);

        $stranger = $this->keys(app(Homepage::class)->for($this->visitor('visitor-stranger-xxxxxxxxxxxxx')));
        $this->assertNotContains('continue', $stranger);
        $this->assertNotContains('interests', $stranger);
    }

    public function test_reorder_lists_past_purchases_that_can_still_be_bought(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $keep = $this->makeListing($seller, ['name' => 'Rooibos tea', 'stock' => 50]);
        $gone = $this->makeListing($seller, ['name' => 'Discontinued tea', 'stock' => 50]);
        $this->paidOrder($buyer, [$keep, $gone]);
        $gone->update(['is_active' => false]);

        $section = collect(app(Homepage::class)->for(Actor::forUser($buyer)))->firstWhere('key', 'reorder');

        $this->assertSame([$keep->id], array_map(fn ($i) => $i->product->id, $section->items));
        $this->assertStringStartsWith('You bought this on ', $section->items[0]->basis);
        $this->assertNull(collect(app(Homepage::class)->for($this->visitor()))->firstWhere('key', 'reorder'), 'a visitor has no purchases');
    }

    public function test_local_sellers_need_a_known_region(): void
    {
        $store = $this->store('Karoo Goods');
        $this->catalogue->storeRegions[$store->id] = 'Western Cape';
        $this->listing($store, 'Karoo biltong', 120.0);
        $this->reindex();

        $this->assertNull(collect(app(Homepage::class)->for($this->visitor()))->firstWhere('key', 'local'));
        $section = collect(app(Homepage::class)->for($this->visitor(), 'Western Cape'))->firstWhere('key', 'local');

        $this->assertSame('Karoo Goods', $section->items[0]['name']);
        $this->assertNull(collect(app(Homepage::class)->for($this->visitor(), 'Gauteng'))->firstWhere('key', 'local'));
    }

    public function test_every_section_states_its_basis(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller);
        app(Collections::class)->create($this->operator(), ['title' => 'Picks', 'visibility' => 'public', 'product_ids' => [$this->makeListing($seller)->id]]);

        foreach (app(Homepage::class)->for($this->visitor()) as $section) {
            $this->assertNotSame('', $section->basis, $section->key.' has no basis');
            $this->assertNotEmpty($section->items, $section->key.' is empty but present');
        }
    }

    public function test_the_homepage_query_count_is_bounded(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();

        foreach (range(1, 6) as $i) {
            $this->makeListing($seller, ['name' => 'Product '.$i, 'stock' => 20]);
        }

        $this->paidOrder($buyer, [$this->makeListing($seller, ['stock' => 20])]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(Homepage::class)->for(Actor::forUser($buyer), 'Gauteng');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(45, $count, "the homepage ran {$count} queries");
    }
}
