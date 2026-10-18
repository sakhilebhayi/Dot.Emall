<?php

namespace Tests\Feature\Marketplace;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\PromoCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function fillCart($buyer, $product, int $qty = 1): void
    {
        CartItem::withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $buyer->id, 'product_id' => $product->id],
            ['quantity' => $qty],
        );
    }

    private function submit($buyer, string $token, array $extra = [])
    {
        return $this->actingAs($buyer)->post(route('cart.checkout'), array_merge([
            'checkout_token' => $token,
            'shipping_address' => '1 Main St',
        ], $extra));
    }

    public function test_a_checkout_without_a_token_is_refused(): void
    {
        $buyer = $this->makeBuyer();
        $this->fillCart($buyer, $this->makeListing($this->makeSeller()));

        $this->actingAs($buyer)->post(route('cart.checkout'), ['shipping_address' => '1 Main St'])
            ->assertSessionHasErrors('checkout_token');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_token_that_is_not_a_uuid_is_refused(): void
    {
        $buyer = $this->makeBuyer();
        $this->fillCart($buyer, $this->makeListing($this->makeSeller()));

        $this->submit($buyer, 'not-a-token')->assertSessionHasErrors('checkout_token');
    }

    public function test_replaying_the_same_submission_creates_no_second_order_and_takes_no_extra_stock(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 10, 'price' => 50]);
        $promo = PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'ONCE', 'discount_value' => 10]);
        $buyer = $this->makeBuyer();
        $token = (string) Str::uuid();

        $this->fillCart($buyer, $product, 2);
        $first = $this->submit($buyer, $token, ['promo_code' => 'ONCE']);
        $order = Order::where('user_id', $buyer->id)->firstOrFail();
        $first->assertRedirect(route('orders.show', $order));

        // Double click / browser back + resubmit / retried request: same token, cart now empty.
        $second = $this->submit($buyer, $token, ['promo_code' => 'ONCE']);
        $second->assertRedirect(route('orders.show', $order));
        $second->assertSessionHas('status', fn ($m) => str_contains($m, 'already placed'));

        // Even if the buyer has refilled the cart in the meantime, the old token cannot place a new order.
        $this->fillCart($buyer, $product, 1);
        $this->submit($buyer, $token)->assertRedirect(route('orders.show', $order));

        $this->assertSame(1, Order::count());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(1, $promo->fresh()->used_count);
        $this->assertSame(1, $seller->notifications()->count());
        $this->assertSame(1, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->count(), 'the refilled cart was left alone');
    }

    public function test_a_fresh_token_places_a_new_order(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 10]);
        $buyer = $this->makeBuyer();

        $this->fillCart($buyer, $product, 1);
        $this->submit($buyer, (string) Str::uuid());
        $this->fillCart($buyer, $product, 1);
        $this->submit($buyer, (string) Str::uuid());

        $this->assertSame(2, Order::count());
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_the_same_token_from_a_different_user_is_not_a_replay(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 10]);
        $token = (string) Str::uuid();
        $one = $this->makeBuyer();
        $two = $this->makeBuyer();

        $this->fillCart($one, $product);
        $this->submit($one, $token);
        $this->fillCart($two, $product);
        $this->submit($two, $token);

        $this->assertSame(2, Order::count());
        $this->assertNotSame(Order::where('user_id', $one->id)->value('id'), Order::where('user_id', $two->id)->value('id'));
    }

    public function test_a_replay_never_reveals_another_users_order(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 10]);
        $token = (string) Str::uuid();
        $victim = $this->makeBuyer();
        $attacker = $this->makeBuyer();
        $this->fillCart($victim, $product);
        $this->submit($victim, $token);
        $victimOrder = Order::where('user_id', $victim->id)->firstOrFail();

        $this->fillCart($attacker, $product);
        $response = $this->submit($attacker, $token);

        $response->assertRedirect();
        $this->assertNotSame(route('orders.show', $victimOrder), $response->headers->get('Location'));
    }

    public function test_the_database_itself_refuses_a_duplicate_token_for_one_user(): void
    {
        $buyer = $this->makeBuyer();
        $token = (string) Str::uuid();
        Order::factory()->create(['user_id' => $buyer->id, 'idempotency_key' => $token]);

        $this->expectException(UniqueConstraintViolationException::class);

        Order::factory()->create(['user_id' => $buyer->id, 'idempotency_key' => $token]);
    }

    public function test_orders_without_a_token_may_coexist(): void
    {
        $buyer = $this->makeBuyer();
        Order::factory()->count(2)->create(['user_id' => $buyer->id, 'idempotency_key' => null]);

        $this->assertSame(2, Order::count());
    }

    public function test_a_failed_checkout_does_not_burn_the_token(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 1]);
        $buyer = $this->makeBuyer();
        $token = (string) Str::uuid();
        $this->fillCart($buyer, $product, 3);

        $this->submit($buyer, $token)->assertRedirect(route('cart.index'));
        $this->assertSame(0, Order::count());

        // The buyer lowers the quantity and submits the same form again.
        $this->fillCart($buyer, $product, 1);
        $this->submit($buyer, $token)->assertRedirect();
        $this->assertSame(1, Order::count());
    }
}
