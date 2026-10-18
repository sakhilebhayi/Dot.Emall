<?php

namespace Tests\Feature\Marketplace;

use App\Models\PromoCode;
use App\Services\CheckoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class PromoPerStoreTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function create($seller, string $code, array $extra = [])
    {
        return $this->actingAs($seller)->post(route('seller.promo-codes.store'), array_merge([
            'code' => $code, 'discount_type' => 'percent', 'discount_value' => 10,
        ], $extra));
    }

    public function test_two_stores_may_each_have_the_same_code(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();

        $this->create($a, 'SUMMER10')->assertSessionHasNoErrors();
        $this->create($b, 'SUMMER10')->assertSessionHasNoErrors();

        $this->assertSame(2, PromoCode::where('code', 'SUMMER10')->count());
    }

    public function test_a_store_cannot_repeat_its_own_code_in_any_letter_case(): void
    {
        $a = $this->makeSeller();
        $this->create($a, 'SUMMER10');

        $this->create($a, 'summer10')->assertSessionHasErrors('code');
        $this->create($a, 'Summer10')->assertSessionHasErrors('code');

        $this->assertSame(1, PromoCode::count());
    }

    public function test_the_database_enforces_one_code_per_store(): void
    {
        $a = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'DUPE']);

        $this->expectException(UniqueConstraintViolationException::class);

        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'DUPE']);
    }

    public function test_the_code_matches_case_insensitively_at_checkout(): void
    {
        $seller = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'SUMMER10']);

        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller, ['price' => 100]), 1]], ['promo_code' => '  sUmMeR10 ']);

        $this->assertEquals(10, (float) $order->discount_amount);
    }

    public function test_a_shared_code_applies_to_the_store_that_is_in_the_cart(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'SUMMER10', 'discount_value' => 10]);
        $promoB = PromoCode::factory()->create(['store_id' => $b->store->id, 'code' => 'SUMMER10', 'discount_value' => 50]);

        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($b, ['price' => 100]), 1]], ['promo_code' => 'SUMMER10']);

        $this->assertEquals(50, (float) $order->discount_amount);
        $this->assertSame($b->store->id, $order->promo_store_id);
        $this->assertSame(1, $promoB->fresh()->used_count);
        $this->assertSame(0, PromoCode::where('store_id', $a->store->id)->value('used_count'));
    }

    public function test_when_two_carted_stores_share_a_code_the_bigger_discount_wins(): void
    {
        $a = $this->makeSeller();
        $b = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $a->store->id, 'code' => 'SUMMER10', 'discount_value' => 10]);
        PromoCode::factory()->create(['store_id' => $b->store->id, 'code' => 'SUMMER10', 'discount_value' => 50]);

        $order = $this->placeOrder($this->makeBuyer(), [
            [$this->makeListing($a, ['price' => 100]), 1],
            [$this->makeListing($b, ['price' => 100]), 1],
        ], ['promo_code' => 'SUMMER10']);

        $this->assertSame($b->store->id, $order->promo_store_id);
        $this->assertEquals(50, (float) $order->discount_amount);
    }

    public function test_a_code_belonging_to_a_store_not_in_the_cart_does_not_apply(): void
    {
        $mine = $this->makeSeller();
        $theirs = $this->makeSeller();
        PromoCode::factory()->create(['store_id' => $theirs->store->id, 'code' => 'ELSEWHERE']);

        $this->expectException(CheckoutException::class);

        $this->placeOrder($this->makeBuyer(), [[$this->makeListing($mine), 1]], ['promo_code' => 'ELSEWHERE']);
    }

    public function test_created_codes_are_stored_in_upper_case(): void
    {
        $a = $this->makeSeller();
        $this->create($a, 'mixedCase7');

        $this->assertDatabaseHas('promo_codes', ['store_id' => $a->store->id, 'code' => 'MIXEDCASE7']);
    }
}
