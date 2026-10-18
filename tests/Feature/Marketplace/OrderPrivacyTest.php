<?php

namespace Tests\Feature\Marketplace;

use App\Enums\OrderStatus;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

/**
 * A mixed-seller order: seller A sells the kettle, seller B the lantern.
 * Each must see their own line and nothing of the other's.
 */
class OrderPrivacyTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private User $sellerA;

    private User $sellerB;

    private User $buyer;

    private $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sellerA = $this->makeSeller(['name' => 'Alpha Copperworks Trading']);
        $this->sellerB = $this->makeSeller(['name' => 'Bravo Lanterns Collective']);
        $this->buyer = $this->makeBuyer();

        $kettle = $this->makeListing($this->sellerA, ['name' => 'Hand-hammered Copper Kettle', 'price' => 120]);
        $lantern = $this->makeListing($this->sellerB, ['name' => 'Wrought Iron Storm Lantern', 'price' => 333.33]);
        PromoCode::factory()->create(['store_id' => $this->sellerB->store->id, 'code' => 'LANTERNLOVE', 'discount_value' => 10]);

        $this->order = $this->placeOrder($this->buyer, [[$kettle, 1], [$lantern, 1]], ['promo_code' => 'LANTERNLOVE']);
    }

    public function test_a_seller_sees_only_their_own_line_of_a_shared_order(): void
    {
        $page = $this->actingAs($this->sellerA)->get(route('orders.show', $this->order))->assertOk();

        $page->assertSee('Hand-hammered Copper Kettle');
        $page->assertDontSee('Wrought Iron Storm Lantern');
        $page->assertDontSee('Bravo Lanterns Collective');
    }

    public function test_a_seller_never_sees_the_order_total_subtotal_or_promo_code(): void
    {
        $page = $this->actingAs($this->sellerA)->get(route('orders.show', $this->order));

        $page->assertDontSee('LANTERNLOVE');
        $page->assertDontSee(number_format((float) $this->order->fresh()->total, 2));
        $page->assertDontSee(number_format((float) $this->order->fresh()->subtotal, 2));
        $page->assertDontSee('Total (unpaid)');
        $page->assertSee('Your items, net of promo');
    }

    public function test_the_seller_with_the_promo_sees_their_own_net_after_discount_only(): void
    {
        $page = $this->actingAs($this->sellerB)->get(route('orders.show', $this->order))->assertOk();

        $page->assertSee('Wrought Iron Storm Lantern');
        $page->assertDontSee('Hand-hammered Copper Kettle');
        // 333.33 less 10% (33.33) = 300.00
        $page->assertSee('300.00');
        $this->assertSame(30000, $page->viewData('view')['sellerNetCents']);
    }

    public function test_a_seller_sees_the_name_address_and_phone_for_lines_they_must_ship(): void
    {
        $page = $this->actingAs($this->sellerA)->get(route('orders.show', $this->order))->assertOk();

        $page->assertSee('Thandiwe Mokoena');
        $page->assertSee('14 Protea Lane, Parkhurst, Johannesburg, 2193');
        $page->assertSee('+27 82 555 0142');
    }

    public function test_shipping_details_disappear_once_nothing_is_left_to_ship(): void
    {
        $this->order->items->firstWhere('seller_id', $this->sellerA->id)->update(['status' => OrderStatus::Delivered]);

        $page = $this->actingAs($this->sellerA)->get(route('orders.show', $this->order))->assertOk();

        $page->assertDontSee('14 Protea Lane');
        $page->assertDontSee('+27 82 555 0142');
        $page->assertDontSee('Thandiwe Mokoena');
        $page->assertSee('hidden');
    }

    public function test_cancelled_lines_do_not_entitle_a_seller_to_shipping_details(): void
    {
        $this->order->items->firstWhere('seller_id', $this->sellerA->id)->update(['status' => OrderStatus::Cancelled]);

        $this->actingAs($this->sellerA)->get(route('orders.show', $this->order))
            ->assertOk()
            ->assertDontSee('14 Protea Lane');
    }

    public function test_the_buyer_sees_everything_on_their_order(): void
    {
        $page = $this->actingAs($this->buyer)->get(route('orders.show', $this->order))->assertOk();

        $page->assertSee('Hand-hammered Copper Kettle');
        $page->assertSee('Wrought Iron Storm Lantern');
        $page->assertSee('LANTERNLOVE');
        $page->assertSee('14 Protea Lane, Parkhurst, Johannesburg, 2193');
        $page->assertSee('pending and unpaid');
    }

    public function test_a_platform_operator_sees_every_line_and_the_shipping_details(): void
    {
        $operator = User::factory()->withPersonalTeam()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        $page = $this->actingAs($operator)->get(route('orders.show', $this->order))->assertOk();

        $page->assertSee('Hand-hammered Copper Kettle');
        $page->assertSee('Wrought Iron Storm Lantern');
        $page->assertSee('14 Protea Lane');
    }

    public function test_a_seller_with_no_line_on_the_order_is_refused(): void
    {
        $this->actingAs($this->makeSeller())->get(route('orders.show', $this->order))->assertForbidden();
    }

    public function test_a_stranger_is_refused(): void
    {
        $this->actingAs($this->makeBuyer())->get(route('orders.show', $this->order))->assertForbidden();
    }

    public function test_the_seller_order_list_never_loads_other_sellers_lines_or_totals(): void
    {
        $page = $this->actingAs($this->sellerA)->get(route('seller.orders.index'))->assertOk();

        $page->assertSee($this->order->reference());
        $page->assertDontSee('Wrought Iron Storm Lantern');
        $page->assertDontSee('LANTERNLOVE');
        $page->assertDontSee(number_format((float) $this->order->fresh()->total, 2));

        $orders = $page->viewData('orders');
        $this->assertCount(1, $orders->first()->items, 'only this seller\'s line is even loaded');
    }

    public function test_the_buyers_name_is_hidden_in_the_seller_list_once_nothing_is_left_to_ship(): void
    {
        $this->order->items->firstWhere('seller_id', $this->sellerA->id)->update(['status' => OrderStatus::Delivered]);

        $this->actingAs($this->sellerA)->get(route('seller.orders.index'))
            ->assertOk()
            ->assertDontSee('Thandiwe Mokoena');
    }

    public function test_a_seller_cannot_change_another_sellers_line(): void
    {
        $lanternLine = $this->order->items->firstWhere('seller_id', $this->sellerB->id);

        $this->actingAs($this->sellerA)
            ->patch(route('seller.order-items.update-status', $lanternLine), ['status' => 'processing'])
            ->assertForbidden();
    }

    public function test_legacy_orders_without_a_shipping_name_fall_back_to_the_buyers_account_name(): void
    {
        $this->order->update(['shipping_name' => null]);
        $this->buyer->update(['name' => 'Account Holder Nomvula Dlamini']);

        $this->actingAs($this->sellerA)->get(route('orders.show', $this->order))
            ->assertSee('Account Holder Nomvula Dlamini');
    }
}
