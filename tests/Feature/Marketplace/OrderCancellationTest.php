<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_a_buyer_cancelling_a_line_restocks_it_and_recomputes_the_order(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['price' => 40, 'stock' => 10]);
        $b = $this->makeListing($seller, ['price' => 60, 'stock' => 10]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 2], [$b, 1]]);

        $this->assertSame(8, $a->fresh()->stock);
        $lineA = $order->items->firstWhere('product_id', $a->id);

        $this->actingAs($buyer)
            ->post(route('order-items.cancel', $lineA))
            ->assertRedirect();

        $this->assertSame(10, $a->fresh()->stock, 'cancelled quantity goes back on the shelf');
        $this->assertSame(OrderStatus::Cancelled, $lineA->fresh()->status);

        $order = $order->fresh();
        $this->assertEquals(60, (float) $order->subtotal, 'cancelled line no longer counts');
        $this->assertEquals(60, (float) $order->total);
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    public function test_cancelling_every_line_cancels_the_order(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['price' => 10]);
        $b = $this->makeListing($seller, ['price' => 10]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 1], [$b, 1]]);

        foreach ($order->items as $line) {
            $this->actingAs($buyer)->post(route('order-items.cancel', $line));
        }

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertEquals(0, (float) $order->total);
        $this->assertEquals(0, (float) $order->subtotal);
    }

    public function test_cancelling_the_same_line_twice_restocks_only_once(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['price' => 10, 'stock' => 5]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 3]]);
        $line = $order->items->first();

        $this->actingAs($buyer)->post(route('order-items.cancel', $line));
        $this->actingAs($buyer)->post(route('order-items.cancel', $line));
        app(OrderLifecycle::class)->cancelItem($line);

        $this->assertSame(5, $a->fresh()->stock, 'stock must come back exactly once');
    }

    public function test_a_seller_can_cancel_their_own_line_before_it_ships(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['price' => 25, 'stock' => 4]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 2]]);
        $line = $order->items->first();

        $this->actingAs($seller)
            ->patch(route('seller.order-items.update-status', $line), ['status' => 'cancelled'])
            ->assertRedirect();

        $this->assertSame(4, $a->fresh()->stock);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(1, $buyer->fresh()->unreadNotifications()->count(), 'the buyer is told');
    }

    public function test_a_buyer_cancelling_notifies_the_seller(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['name' => 'Notified Pewter Tankard']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 1]]);
        $seller->notifications()->delete();

        $this->actingAs($buyer)->post(route('order-items.cancel', $order->items->first()));

        $this->assertSame(1, $seller->fresh()->unreadNotifications()->count());
        $this->assertStringContainsString('Notified Pewter Tankard', $seller->fresh()->unreadNotifications->first()->data['message']);
    }

    #[DataProvider('unshippableStatuses')]
    public function test_a_shipped_or_delivered_line_cannot_be_cancelled_by_anyone(OrderStatus $status): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['stock' => 5]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 1]]);
        $line = $order->items->first();
        $line->update(['status' => $status]);

        $this->actingAs($buyer)->post(route('order-items.cancel', $line))->assertSessionHasErrors('status');
        $this->actingAs($seller)
            ->patch(route('seller.order-items.update-status', $line), ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');

        $this->assertSame($status, $line->fresh()->status);
        $this->assertSame(4, $a->fresh()->stock);
    }

    public static function unshippableStatuses(): array
    {
        return [[OrderStatus::Shipped], [OrderStatus::Delivered]];
    }

    public function test_someone_with_no_part_in_the_order_cannot_cancel_a_line(): void
    {
        $seller = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]]);
        $line = $order->items->first();

        $this->actingAs($this->makeBuyer())->post(route('order-items.cancel', $line))->assertForbidden();
        $this->actingAs($this->makeSeller())->post(route('order-items.cancel', $line))->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $line->fresh()->status);
    }

    public function test_a_promo_use_is_given_back_exactly_once_when_no_eligible_line_is_left(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['price' => 50]);
        $b = $this->makeListing($seller, ['price' => 50]);
        $promo = PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'SAVE20', 'discount_value' => 20, 'max_uses' => 5]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 1], [$b, 1]], ['promo_code' => 'save20']);

        $this->assertSame(1, $promo->fresh()->used_count);
        $this->assertEquals(20, (float) $order->fresh()->discount_amount);

        [$first, $second] = $order->items;

        $this->actingAs($buyer)->post(route('order-items.cancel', $first));
        $this->assertSame(1, $promo->fresh()->used_count, 'a discounted line is still on the order, so the use still counts');
        $this->assertEquals(10, (float) $order->fresh()->discount_amount, 'discount shrinks with the eligible subtotal');
        $this->assertEquals(40, (float) $order->fresh()->total);

        $this->actingAs($buyer)->post(route('order-items.cancel', $second));
        $this->assertSame(0, $promo->fresh()->used_count, 'no eligible line left, use returned');
        $this->assertEquals(0, (float) $order->fresh()->discount_amount);
        $this->assertNotNull($order->fresh()->promo_released_at);

        // Replays and the lifecycle service called again must not go negative or double-release.
        $this->actingAs($buyer)->post(route('order-items.cancel', $second));
        app(OrderLifecycle::class)->cancelItem($second);
        $this->assertSame(0, $promo->fresh()->used_count);
    }

    public function test_cancelling_a_line_from_another_store_leaves_the_promo_alone(): void
    {
        $promoSeller = $this->makeSeller();
        $otherSeller = $this->makeSeller();
        $a = $this->makeListing($promoSeller, ['price' => 100]);
        $b = $this->makeListing($otherSeller, ['price' => 30]);
        $promo = PromoCode::factory()->create(['store_id' => $promoSeller->store->id, 'code' => 'HALF', 'discount_value' => 50]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 1], [$b, 1]], ['promo_code' => 'HALF']);

        $this->assertEquals(50, (float) $order->fresh()->discount_amount);

        $this->actingAs($buyer)->post(route('order-items.cancel', $order->items->firstWhere('product_id', $b->id)));

        $this->assertSame(1, $promo->fresh()->used_count);
        $this->assertEquals(50, (float) $order->fresh()->discount_amount);
        $this->assertEquals(50, (float) $order->fresh()->total);
    }

    public function test_a_fixed_discount_is_capped_by_what_remains_eligible(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['price' => 30]);
        $b = $this->makeListing($seller, ['price' => 8]);
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'TENOFF', 'discount_type' => 'fixed', 'discount_value' => 10]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 1], [$b, 1]], ['promo_code' => 'TENOFF']);

        $this->assertEquals(10, (float) $order->fresh()->discount_amount);

        $this->actingAs($buyer)->post(route('order-items.cancel', $order->items->firstWhere('product_id', $a->id)));

        $order = $order->fresh();
        $this->assertEquals(8, (float) $order->discount_amount, 'fixed 10 capped at the 8 still eligible');
        $this->assertEquals(0, (float) $order->total);
    }

    public function test_cancelling_restocks_even_when_the_listing_was_archived_meanwhile(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['stock' => 3]);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 2]]);
        $a->delete();

        $this->actingAs($buyer)->post(route('order-items.cancel', $order->items->first()));

        $this->assertSame(3, Product::withTrashed()->find($a->id)->stock);
    }

    public function test_discount_shares_always_add_up_to_the_order_discount(): void
    {
        $seller = $this->makeSeller();
        $lines = collect([33.33, 33.33, 33.34])->map(fn ($price) => [$this->makeListing($seller, ['price' => $price]), 1])->all();
        PromoCode::factory()->create(['store_id' => $seller->store->id, 'code' => 'ODDCENTS', 'discount_type' => 'fixed', 'discount_value' => 10]);

        $order = $this->placeOrder($this->makeBuyer(), $lines, ['promo_code' => 'ODDCENTS']);

        $shares = $order->items()->pluck('discount_amount')->map(fn ($v) => (int) round($v * 100));
        $this->assertSame(1000, $shares->sum());
        $this->assertSame(1000, (int) round($order->fresh()->discount_amount * 100));
    }

    public function test_the_buyer_order_page_offers_cancel_only_for_cancellable_lines(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['name' => 'Cancellable Enamel Mug Alpha']);
        $b = $this->makeListing($seller, ['name' => 'Already Shipped Enamel Mug Beta']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$a, 1], [$b, 1]]);
        $order->items->firstWhere('product_id', $b->id)->update(['status' => OrderStatus::Shipped]);

        $page = $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk();

        $page->assertSee(route('order-items.cancel', $order->items->firstWhere('product_id', $a->id)), false);
        $page->assertDontSee(route('order-items.cancel', $order->items->firstWhere('product_id', $b->id)), false);
    }

    public function test_unknown_status_values_are_rejected_by_the_seller_route(): void
    {
        $seller = $this->makeSeller();
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($seller), 1]]);

        $this->actingAs($seller)
            ->patch(route('seller.order-items.update-status', $order->items->first()), ['status' => 'refunded'])
            ->assertSessionHasErrors('status');
    }

    public function test_orders_and_lines_exist_in_the_database_with_enum_strings_only(): void
    {
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller()), 1]]);

        $this->assertSame('pending', Order::query()->toBase()->where('id', $order->id)->value('status'));
        $this->assertSame('pending', OrderItem::query()->toBase()->where('order_id', $order->id)->value('status'));
    }
}
