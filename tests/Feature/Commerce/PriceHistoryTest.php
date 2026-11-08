<?php

namespace Tests\Feature\Commerce;

use App\Models\ProductPriceHistory;
use App\Services\Catalogue\ReferencePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class PriceHistoryTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price = 100)
    {
        return $this->makeListing($this->makeSeller(), ['price' => $price, 'stock' => 10]);
    }

    /** Changes the price as it would be changed on `$daysAgo`. */
    private function change($product, float $price, int $daysAgo): void
    {
        Carbon::setTestNow(now()->startOfDay()->subDays($daysAgo)->addHours(10));
        $product->refresh()->update(['price' => $price]);
        Carbon::setTestNow();
    }

    public function test_creating_a_product_and_every_price_change_is_recorded_with_who_and_when(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 50]);

        $this->actingAs($seller);
        $product->update(['price' => 45]);
        $product->update(['name' => 'Renamed, same price']);
        $product->update(['price' => 45]);
        $product->update(['price' => 40]);

        $rows = ProductPriceHistory::query()->where('product_id', $product->id)->orderBy('id')->get();

        $this->assertSame([null, '50.00', '45.00'], $rows->pluck('old_price')->all());
        $this->assertSame(['50.00', '45.00', '40.00'], $rows->pluck('new_price')->all());
        $this->assertNull($rows[0]->actor_id, 'the opening price was recorded outside a signed-in request');
        $this->assertSame($seller->id, $rows[2]->actor_id);
        $this->assertNotNull($rows[2]->changed_at);
        $this->assertSame($product->defaultVariant()->first()->id, $rows[2]->variant_id);
    }

    public function test_a_price_that_dropped_recently_after_a_long_history_has_a_was_price(): void
    {
        $product = $this->product(100);
        // Pretend the 100 was recorded 45 days ago and the drop to 80 happened 3 days ago.
        ProductPriceHistory::query()->where('product_id', $product->id)->update(['changed_at' => now()->subDays(45)]);
        $this->change($product, 80, 3);

        $reference = ReferencePrice::for($product->fresh());

        $this->assertNotNull($reference);
        $this->assertSame(10000, $reference['cents']);
        $this->assertSame(30, $reference['window_days']);
    }

    public function test_the_reference_is_the_lowest_price_in_the_window_not_the_highest(): void
    {
        $product = $this->product(100);
        ProductPriceHistory::query()->where('product_id', $product->id)->update(['changed_at' => now()->subDays(60)]);
        $this->change($product, 90, 20);
        $this->change($product, 95, 10);
        $this->change($product, 85, 2);

        $this->assertSame(9000, ReferencePrice::for($product->fresh())['cents']);
    }

    public function test_a_price_dip_inside_the_window_that_is_below_today_means_no_was_price(): void
    {
        $product = $this->product(100);
        ProductPriceHistory::query()->where('product_id', $product->id)->update(['changed_at' => now()->subDays(60)]);
        $this->change($product, 70, 15);
        $this->change($product, 80, 2);

        $this->assertNull(ReferencePrice::for($product->fresh()), 'it was cheaper than today within the month, so today is not a discount');
    }

    public function test_a_history_shorter_than_thirty_days_never_produces_a_was_price(): void
    {
        $product = $this->product(100);
        ProductPriceHistory::query()->where('product_id', $product->id)->update(['changed_at' => now()->subDays(29)]);
        $this->change($product, 70, 1);

        $this->assertNull(ReferencePrice::for($product->fresh()));
    }

    public function test_a_price_that_has_not_changed_or_went_up_has_no_was_price(): void
    {
        $same = $this->product(100);
        ProductPriceHistory::query()->where('product_id', $same->id)->update(['changed_at' => now()->subDays(90)]);
        $this->assertNull(ReferencePrice::for($same->fresh()));

        $up = $this->product(100);
        ProductPriceHistory::query()->where('product_id', $up->id)->update(['changed_at' => now()->subDays(90)]);
        $this->change($up, 120, 5);
        $this->assertNull(ReferencePrice::for($up->fresh()));
    }

    public function test_a_price_that_has_been_low_for_over_a_month_has_no_was_price(): void
    {
        $product = $this->product(100);
        ProductPriceHistory::query()->where('product_id', $product->id)->update(['changed_at' => now()->subDays(90)]);
        $this->change($product, 70, 40);

        $this->assertNull(ReferencePrice::for($product->fresh()), 'the 100 ended 40 days ago, outside the 30-day window');
    }

    public function test_a_seller_typed_compare_price_is_never_used(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 50, 'compare_price' => 999]);
        ProductPriceHistory::query()->where('product_id', $product->id)->update(['changed_at' => now()->subDays(90)]);

        $this->assertNull(ReferencePrice::for($product->fresh()));
    }

    public function test_history_that_disagrees_with_the_live_price_proves_nothing(): void
    {
        $product = $this->product(100);
        ProductPriceHistory::query()->where('product_id', $product->id)->update(['changed_at' => now()->subDays(60)]);
        $this->change($product, 80, 3);
        // An update that bypassed the model leaves the record stale.
        DB::table('product_variants')->where('product_id', $product->id)->update(['price' => 60]);

        $this->assertNull(ReferencePrice::for($product->fresh()));
    }

    public function test_a_variant_has_its_own_history(): void
    {
        $product = $this->product(100);
        $variant = $product->defaultVariant()->first();
        ProductPriceHistory::query()->where('variant_id', $variant->id)->update(['changed_at' => now()->subDays(50)]);
        $this->change($product, 75, 1);

        $this->assertSame(10000, ReferencePrice::for($variant->fresh())['cents']);
        $this->assertSame(10000, ReferencePrice::for($product->fresh())['cents']);
    }
}
