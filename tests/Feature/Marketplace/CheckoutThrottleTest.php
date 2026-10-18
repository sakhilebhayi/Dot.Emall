<?php

namespace Tests\Feature\Marketplace;

use App\Models\CartItem;
use App\Models\PromoCode;
use App\Support\Throttles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class CheckoutThrottleTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function submit($buyer, array $extra = [])
    {
        return $this->actingAs($buyer)->post(route('cart.checkout'), array_merge([
            'checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St',
        ], $extra));
    }

    private function fillCart($buyer, $product): void
    {
        CartItem::withoutGlobalScopes()->updateOrCreate(['user_id' => $buyer->id, 'product_id' => $product->id], ['quantity' => 1]);
    }

    public function test_the_eleventh_checkout_submission_in_a_minute_is_throttled(): void
    {
        $buyer = $this->makeBuyer();

        for ($i = 0; $i < 10; $i++) {
            $this->submit($buyer)->assertStatus(302);
        }

        $this->submit($buyer)->assertStatus(429);
    }

    public function test_one_buyers_throttle_does_not_block_another(): void
    {
        $a = $this->makeBuyer();
        $b = $this->makeBuyer();

        for ($i = 0; $i < 10; $i++) {
            $this->submit($a);
        }

        $this->submit($a)->assertStatus(429);
        $this->submit($b)->assertStatus(302);
    }

    public function test_a_wrong_promo_code_is_counted_against_the_user_and_the_ip(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $buyer = $this->makeBuyer();
        $this->fillCart($buyer, $product);

        $this->submit($buyer, ['promo_code' => 'GUESS1']);

        $this->assertSame(1, RateLimiter::attempts(Throttles::promoUserKey($buyer->id)));
        $this->assertSame(1, RateLimiter::attempts(Throttles::promoIpKey('127.0.0.1')));
    }

    public function test_a_valid_promo_code_does_not_use_up_the_attempt_budget(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'GOOD']);
        $buyer = $this->makeBuyer();
        $this->fillCart($buyer, $product);

        $this->submit($buyer, ['promo_code' => 'GOOD'])->assertRedirect();

        $this->assertSame(0, RateLimiter::attempts(Throttles::promoUserKey($buyer->id)));
    }

    public function test_after_ten_wrong_guesses_even_the_right_code_is_refused_for_that_user(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'REALCODE']);
        $buyer = $this->makeBuyer();
        $this->fillCart($buyer, $product);

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit(Throttles::promoUserKey($buyer->id), 60);
        }

        $this->submit($buyer, ['promo_code' => 'REALCODE'])
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Too many promo code attempts'));

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_per_ip_budget_is_shared_by_different_accounts(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'REALCODE']);
        $buyer = $this->makeBuyer();
        $this->fillCart($buyer, $product);

        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit(Throttles::promoIpKey('127.0.0.1'), 60);
        }

        $this->submit($buyer, ['promo_code' => 'REALCODE'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Too many promo code attempts'));
    }

    public function test_the_promo_budget_does_not_affect_checkouts_without_a_code(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        $this->fillCart($buyer, $product);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit(Throttles::promoUserKey($buyer->id), 60);
        }

        $this->submit($buyer)->assertRedirect()->assertSessionMissing('error');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_every_way_a_code_can_be_wrong_gets_the_same_answer_so_codes_cannot_be_enumerated(): void
    {
        $mine = $this->makeSeller();
        $theirs = $this->makeSeller();
        $product = $this->makeListing($mine);
        PromoCode::factory()->create(['store_id' => $theirs->store->id, 'code' => 'OTHERSTORE']);
        PromoCode::factory()->create(['store_id' => $mine->store->id, 'code' => 'EXPIRED', 'expires_at' => now()->subDay()]);
        PromoCode::factory()->create(['store_id' => $mine->store->id, 'code' => 'USEDUP', 'max_uses' => 1, 'used_count' => 1]);
        $buyer = $this->makeBuyer();

        $messages = [];
        foreach (['NOSUCHCODE', 'OTHERSTORE', 'EXPIRED', 'USEDUP'] as $code) {
            $this->fillCart($buyer, $product);
            $messages[] = $this->submit($buyer, ['promo_code' => $code])->getSession()->get('error');
            RateLimiter::clear(Throttles::promoUserKey($buyer->id));
            RateLimiter::clear(Throttles::promoIpKey('127.0.0.1'));
        }

        $this->assertCount(1, array_unique($messages), 'all four failures look identical: '.json_encode($messages));
        $this->assertNotEmpty($messages[0]);
    }
}
