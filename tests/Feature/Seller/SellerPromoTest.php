<?php

namespace Tests\Feature\Seller;

use App\Models\PromoCode;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartQuote;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/** Promotions: the form and its conditions, enforcement in the cart and at checkout, pausing, and the usage log of real redemptions. */
class SellerPromoTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_code_is_created_with_a_minimum_spend_a_cap_per_buyer_an_expiry_free_delivery_and_a_conditions_note(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->post(route('seller.promo-codes.store'), [
            'code' => 'welcome10', 'discount_type' => 'percent', 'discount_value' => '10', 'min_spend' => '300', 'max_uses' => 50, 'per_user_limit' => 1,
            'expires_at' => now()->addDays(10)->toDateString(), 'free_shipping' => '1', 'conditions' => 'Not valid on sale items.',
        ])->assertSessionHasNoErrors()->assertRedirect(route('seller.promo-codes.index'));

        $code = PromoCode::query()->where('code', 'WELCOME10')->first();
        $this->assertSame(['300.00', 50, 1, true, 'Not valid on sale items.'], [$code->min_spend, $code->max_uses, $code->per_user_limit, $code->free_shipping, $code->conditions]);
        $this->assertSame(now()->addDays(10)->endOfDay()->toDateTimeString(), $code->expires_at->toDateTimeString(), 'a date means the end of that day');
        $this->get(route('seller.promo-codes.index'))->assertSee('WELCOME10')->assertSee('10% off')->assertSee('free delivery')->assertSee('Spend R300.00 or more')->assertSee('1 per buyer')->assertSee('Not valid on sale items.');
    }

    public function test_the_form_refuses_bad_conditions(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller);
        $base = ['code' => 'GOOD1', 'discount_type' => 'percent', 'discount_value' => '10'];

        $this->post(route('seller.promo-codes.store'), $base + ['min_spend' => '-5'])->assertSessionHasErrors('min_spend');
        $this->post(route('seller.promo-codes.store'), $base + ['min_spend' => '10.999'])->assertSessionHasErrors('min_spend');
        $this->post(route('seller.promo-codes.store'), $base + ['per_user_limit' => 0])->assertSessionHasErrors('per_user_limit');
        $this->post(route('seller.promo-codes.store'), $base + ['expires_at' => now()->subDay()->toDateString()])->assertSessionHasErrors('expires_at');
        $this->post(route('seller.promo-codes.store'), $base + ['conditions' => str_repeat('x', 501)])->assertSessionHasErrors('conditions');
        $this->post(route('seller.promo-codes.store'), ['code' => 'OVER', 'discount_type' => 'percent', 'discount_value' => '150'])->assertSessionHasErrors('discount_value');
        $this->assertSame(0, PromoCode::count());
    }

    public function test_a_minimum_spend_is_enforced_in_the_cart_preview_and_at_checkout_with_the_one_message(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 20]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'BIG', 'discount_type' => 'fixed', 'discount_value' => 50, 'min_spend' => 250]);
        $buyer = $this->makeBuyer();
        $this->actingAs($buyer);
        $variant = $kettle->variants()->first();
        $cart = app(CartManager::class);

        $cart->add($buyer, $variant, 2); // R200: under the minimum
        $under = app(CartQuote::class)->quote($cart->lines($buyer), null, 'BIG');
        $this->assertNull($under->promo);
        $this->assertSame('That code is not valid for the items in your cart.', $under->promoProblem);

        $cart->setQuantity($buyer, $variant->id, 3); // R300
        $over = app(CartQuote::class)->quote($cart->lines($buyer), null, 'BIG');
        $this->assertSame('BIG', $over->promo?->code);
        $this->assertSame(5000, $over->discountCents);

        $cart->setQuantity($buyer, $variant->id, 2);
        try {
            app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Test Road', 'shipping_name' => 'T', 'promo_code' => 'BIG'], (string) Str::uuid());
            $this->fail('the code should have been refused');
        } catch (CheckoutException $e) {
            $this->assertSame('That promo code is not valid for this order.', $e->getMessage());
        }
        $this->assertSame(0, $buyer->orders()->count());
    }

    public function test_a_cap_per_buyer_counts_that_buyers_own_orders_and_a_guest_by_e_mail(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 20]);
        $code = PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'ONCE', 'discount_type' => 'percent', 'discount_value' => 10, 'per_user_limit' => 1]);
        $buyer = $this->makeBuyer();
        $other = $this->makeBuyer();

        $first = $this->placeOrder($buyer, [[$kettle, 1]], ['promo_code' => 'ONCE']);
        $this->assertSame($code->id, $first->promo_code_id);

        $this->expectException(CheckoutException::class);
        $this->placeOrder($buyer, [[$kettle, 1]], ['promo_code' => 'ONCE']);
    }

    public function test_the_cap_does_not_bind_another_buyer_or_count_a_cancelled_order_and_a_guest_is_known_by_e_mail(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 20]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'ONCE', 'discount_type' => 'percent', 'discount_value' => 10, 'per_user_limit' => 1]);
        $a = $this->makeBuyer();
        $b = $this->makeBuyer();
        $variant = $kettle->variants()->first();
        $guest = fn (string $email) => app(CheckoutService::class)->placeAsGuest($email, [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => '1 Road', 'shipping_name' => 'G', 'promo_code' => 'ONCE'], (string) Str::uuid());

        $one = $this->placeOrder($a, [[$kettle, 1]], ['promo_code' => 'ONCE']);
        $this->assertNotNull($this->placeOrder($b, [[$kettle, 1]], ['promo_code' => 'ONCE'])->promo_code_id, 'a different buyer still has their own use');

        $one->forceFill(['status' => 'cancelled'])->save();
        $this->assertNotNull($this->placeOrder($a, [[$kettle, 1]], ['promo_code' => 'ONCE'])->promo_code_id, 'a cancelled order does not use up the cap');

        $guest('Gina@Example.test');
        $this->expectException(CheckoutException::class);
        $guest('gina@example.test');
    }

    public function test_the_total_use_limit_and_an_expiry_still_work_alongside_the_new_conditions(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 20]);
        $code = PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'LIMITED', 'discount_type' => 'percent', 'discount_value' => 10, 'max_uses' => 1]);

        $this->assertNotNull($this->placeOrder($this->makeBuyer(), [[$kettle, 1]], ['promo_code' => 'LIMITED'])->promo_code_id);
        $this->assertSame(1, $code->fresh()->used_count);

        $this->expectException(CheckoutException::class);
        $this->placeOrder($this->makeBuyer(), [[$kettle, 1]], ['promo_code' => 'LIMITED']);
    }

    public function test_pausing_a_code_stops_it_working_and_switching_it_on_restores_it(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 100, 'stock' => 20]);
        $code = PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'PAUSE', 'discount_type' => 'percent', 'discount_value' => 10]);

        $this->actingAs($seller)->patch(route('seller.promo-codes.toggle', $code))->assertRedirect();
        $this->assertFalse($code->fresh()->is_active);
        $this->get(route('seller.promo-codes.index'))->assertSee('Paused')->assertSee('Switch on');

        $this->patch(route('seller.promo-codes.toggle', $code));
        $this->assertTrue($code->fresh()->is_active);
        $this->get(route('seller.promo-codes.index'))->assertSee('Working');
    }

    public function test_the_usage_log_lists_real_redemptions_without_buyer_names_and_leaves_out_cancelled_orders(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['price' => 200, 'stock' => 20]);
        $code = PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'LOGGED', 'discount_type' => 'percent', 'discount_value' => 10]);
        $a = $this->placeOrder($this->makeBuyer(), [[$kettle, 1]], ['promo_code' => 'LOGGED']);
        $b = $this->placeOrder($this->makeBuyer(), [[$kettle, 1]], ['promo_code' => 'LOGGED']);
        $b->forceFill(['status' => 'cancelled'])->save();

        $page = $this->actingAs($seller)->get(route('seller.promo-codes.index'))->assertOk();

        $page->assertSee('Where it was used')->assertSee($a->reference())->assertDontSee($b->reference())->assertDontSee('Thandiwe Mokoena')->assertSee('R20.00')->assertSee('Used 1')->assertSee('R20.00 given away');
        $this->assertSame(1, $page->viewData('usage')->count());
    }

    public function test_the_page_is_honest_that_a_code_applies_to_the_whole_store(): void
    {
        $this->actingAs($this->makeSeller())->get(route('seller.promo-codes.index'))->assertSee('Applies to everything in your store')->assertSee('a buyer is told only that it is not valid');
    }
}
