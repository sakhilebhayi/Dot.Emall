<?php

namespace Tests\Feature\Discovery;

use App\Models\Product;
use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\RecommendationBuilder;
use App\Services\Discovery\Recommender;
use App\Support\Actor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCatalogue;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPaidOrders;
use Tests\TestCase;

class RecommenderTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsMarketplace;
    use BuildsPaidOrders;
    use RefreshDatabase;

    private Product $laptop;

    private Product $mouse;

    private Product $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
        config(['discovery.recommendations.bought_together_min' => 3, 'discovery.recommendations.viewed_together_min' => 5]);

        $seller = $this->makeSeller();
        $laptops = $this->category('Laptops');
        $mice = $this->category('Mice');
        $this->laptop = $this->makeListing($seller, ['name' => 'Lenovo IdeaPad Laptop', 'category_id' => $laptops->id, 'stock' => 100]);
        $this->mouse = $this->makeListing($seller, ['name' => 'Logitech Wireless Mouse', 'category_id' => $mice->id, 'stock' => 100]);
        $this->bag = $this->makeListing($seller, ['name' => 'Laptop Backpack', 'stock' => 100]);
    }

    private function buyTogether(Product ...$products): void
    {
        $this->paidOrder($this->makeBuyer(), $products);
    }

    private function ids($set): array
    {
        return $set === null ? [] : array_map(fn ($i) => $i->product->id, $set->items);
    }

    public function test_a_new_catalogue_returns_an_honest_empty_set(): void
    {
        app(RecommendationBuilder::class)->rebuild();

        $this->assertNull(app(Recommender::class)->boughtTogether($this->laptop));
        $this->assertNull(app(Recommender::class)->viewedTogether($this->laptop));
        $this->assertNull(app(Recommender::class)->becauseYouViewed($this->visitor()));
    }

    public function test_bought_together_needs_the_minimum_number_of_paid_orders_and_shows_the_count(): void
    {
        $this->buyTogether($this->laptop, $this->mouse);
        $this->buyTogether($this->laptop, $this->mouse);
        app(RecommendationBuilder::class)->rebuild();

        $this->assertNull(app(Recommender::class)->boughtTogether($this->laptop), 'two orders are below the minimum of three');

        $this->buyTogether($this->laptop, $this->mouse, $this->bag);
        app(RecommendationBuilder::class)->rebuild();

        $set = app(Recommender::class)->boughtTogether($this->laptop);

        $this->assertSame([$this->mouse->id], $this->ids($set));
        $this->assertSame('Bought together in 3 orders', $set->items[0]->basis);
        $this->assertSame(3, $set->items[0]->support);
        // It is symmetric.
        $this->assertSame([$this->laptop->id], $this->ids(app(Recommender::class)->boughtTogether($this->mouse)));
    }

    public function test_unpaid_and_cancelled_orders_do_not_count(): void
    {
        foreach (range(1, 3) as $i) {
            $order = $this->placeOrder($this->makeBuyer(), [[$this->laptop, 1], [$this->mouse, 1]]);
            $order->forceFill(['requires_payment' => true, 'payment_status' => 'pending'])->save();
        }

        app(RecommendationBuilder::class)->rebuild();

        $this->assertSame(0, DB::table('product_relations')->count());
    }

    public function test_out_of_stock_unlisted_and_own_store_products_are_never_recommended(): void
    {
        foreach (range(1, 3) as $i) {
            $this->buyTogether($this->laptop, $this->mouse);
        }
        app(RecommendationBuilder::class)->rebuild();
        $recommender = app(Recommender::class);

        $this->assertSame([$this->mouse->id], $this->ids($recommender->boughtTogether($this->laptop)));

        $this->mouse->update(['stock' => 0]);
        Recommender::forgetCache();
        $this->assertNull($recommender->boughtTogether($this->laptop));

        $this->mouse->update(['stock' => 5, 'is_active' => false]);
        $this->assertNull($recommender->boughtTogether($this->laptop));

        $this->mouse->update(['is_active' => true]);
        $this->assertSame([$this->mouse->id], $this->ids($recommender->boughtTogether($this->laptop)));

        // The mouse's own seller is shown nothing from their own store.
        $this->assertNull($recommender->boughtTogether($this->laptop, Actor::forUser($this->mouse->store->owner_id)));
    }

    public function test_viewed_together_counts_distinct_people(): void
    {
        foreach (range(1, 4) as $i) {
            $actor = $this->visitor("visitor-{$i}-".str_repeat((string) $i, 20));
            app(RecentlyViewed::class)->view($actor, $this->laptop);
            app(RecentlyViewed::class)->view($actor, $this->mouse);
        }
        app(RecommendationBuilder::class)->rebuild();
        $this->assertNull(app(Recommender::class)->viewedTogether($this->laptop), 'four people are below the minimum of five');

        $actor = $this->visitor('visitor-5-'.str_repeat('5', 20));
        app(RecentlyViewed::class)->view($actor, $this->laptop);
        app(RecentlyViewed::class)->view($actor, $this->mouse);
        app(RecommendationBuilder::class)->rebuild();

        $set = app(Recommender::class)->viewedTogether($this->laptop);

        $this->assertSame('Also viewed by 5 people', $set->items[0]->basis);
    }

    public function test_complementary_rules_are_editorial_and_labelled_as_such(): void
    {
        DB::table('complementary_rules')->insert(['category_slug' => 'laptops', 'complement_slug' => 'mice', 'created_at' => now(), 'updated_at' => now()]);

        $set = app(Recommender::class)->complementary($this->laptop);

        $this->assertTrue($set->editorial);
        $this->assertSame([$this->mouse->id], $this->ids($set));
        $this->assertStringContainsString('our suggestion', $set->items[0]->basis);
        $this->assertStringContainsString('not what other shoppers did', $set->basis);
        $this->assertNull($set->items[0]->support, 'an editorial link has no behavioural count');
    }

    public function test_same_category_is_labelled_as_exactly_that(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeListing($seller, ['name' => 'Acer Aspire Laptop', 'category_id' => $this->laptop->category_id, 'stock' => 5]);

        $set = app(Recommender::class)->sameCategoryAndBrand($this->laptop);

        $this->assertSame([$other->id], $this->ids($set));
        $this->assertSame('Same category (Laptops)', $set->items[0]->basis);
    }

    public function test_because_you_viewed_and_bought_use_the_persons_own_history(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeListing($seller, ['name' => 'Acer Aspire Laptop', 'category_id' => $this->laptop->category_id, 'stock' => 5]);
        $actor = $this->visitor();
        app(RecentlyViewed::class)->view($actor, $this->laptop);

        $set = app(Recommender::class)->becauseYouViewed($actor);

        $this->assertSame('Because you viewed Lenovo IdeaPad Laptop', $set->title);
        $this->assertSame([$other->id], $this->ids($set));

        $buyer = $this->makeBuyer();
        $this->paidOrder($buyer, [$this->laptop]);
        $bought = app(Recommender::class)->becauseYouBought(Actor::forUser($buyer));
        $this->assertSame('Because you bought Lenovo IdeaPad Laptop', $bought->title);
    }

    public function test_for_product_returns_only_the_blocks_that_have_something_to_say(): void
    {
        $blocks = app(Recommender::class)->forProduct($this->laptop);

        $this->assertSame([], array_map(fn ($b) => $b->key, $blocks), 'an empty catalogue around this product gives no blocks, not filler');

        foreach (range(1, 3) as $i) {
            $this->buyTogether($this->laptop, $this->mouse);
        }
        app(RecommendationBuilder::class)->rebuild();

        $this->assertSame(['bought_together'], array_map(fn ($b) => $b->key, app(Recommender::class)->forProduct($this->laptop)));
    }

    public function test_the_builder_is_idempotent_and_replaces_stale_links(): void
    {
        foreach (range(1, 3) as $i) {
            $this->buyTogether($this->laptop, $this->mouse);
        }

        $builder = app(RecommendationBuilder::class);
        $builder->rebuild();
        $builder->rebuild();
        $this->assertSame(2, DB::table('product_relations')->where('kind', 'bought_together')->count());

        DB::table('order_items')->where('product_id', $this->mouse->id)->update(['status' => 'cancelled']);
        $builder->rebuild();
        $this->assertSame(0, DB::table('product_relations')->count());
    }

    public function test_the_command_runs(): void
    {
        $this->artisan('emall:recommendations-rebuild')->assertSuccessful();
    }
}
