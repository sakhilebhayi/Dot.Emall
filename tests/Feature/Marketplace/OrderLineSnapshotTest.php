<?php

namespace Tests\Feature\Marketplace;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class OrderLineSnapshotTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_checkout_snapshots_name_price_store_and_seller_on_each_line(): void
    {
        $seller = $this->makeSeller(['name' => 'Karoo Copperworks Trading']);
        $product = $this->makeListing($seller, ['name' => 'Hand-hammered Copper Kettle', 'price' => 249.50]);

        $order = $this->placeOrder($this->makeBuyer(), [[$product, 2]]);
        $line = $order->items->first();

        $this->assertSame('Hand-hammered Copper Kettle', $line->product_name);
        $this->assertSame('Karoo Copperworks Trading', $line->store_name);
        $this->assertSame($seller->store->id, $line->store_id);
        $this->assertSame($seller->id, $line->seller_id);
        $this->assertEquals(249.50, (float) $line->unit_price);
        $this->assertEquals(499.00, (float) $line->total);
    }

    public function test_editing_the_product_or_renaming_the_store_never_changes_a_placed_line(): void
    {
        $seller = $this->makeSeller(['name' => 'Karoo Copperworks Trading']);
        $product = $this->makeListing($seller, ['name' => 'Hand-hammered Copper Kettle', 'price' => 100]);
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1]]);

        $product->update(['name' => 'Renamed Kettle Mk Two', 'price' => 999]);
        $seller->store->update(['name' => 'Entirely New Store Name']);

        $line = $order->items()->first();
        $this->assertSame('Hand-hammered Copper Kettle', $line->displayName());
        $this->assertSame('Karoo Copperworks Trading', $line->displayStoreName());
        $this->assertEquals(100, (float) $line->unit_price);
    }

    public function test_hard_deleting_a_product_keeps_the_order_line_and_nulls_the_link(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Discontinued Brass Lantern']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 1]]);

        $product->forceDelete();

        $line = OrderItem::where('order_id', $order->id)->first();
        $this->assertNotNull($line, 'the order line must survive the product being deleted');
        $this->assertNull($line->product_id);
        $this->assertSame('Discontinued Brass Lantern', $line->displayName());
        $this->assertSame($seller->id, $line->seller_id);

        $this->actingAs($buyer)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Discontinued Brass Lantern');
    }

    public function test_a_seller_can_delete_their_own_listing_and_order_history_survives(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Archived Beadwork Necklace']);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 1]]);

        $this->actingAs($seller)
            ->delete(route('seller.products.destroy', $product))
            ->assertRedirect(route('seller.products.index'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'product_name' => 'Archived Beadwork Necklace']);

        $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->assertSee('Archived Beadwork Necklace');
        $this->actingAs($seller)->get(route('orders.show', $order))->assertOk()->assertSee('Archived Beadwork Necklace');
        $this->actingAs($seller)->get(route('dashboard'))->assertOk();
    }

    public function test_an_archived_listing_leaves_the_storefront_and_every_cart(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['name' => 'Soon Archived Clay Pot']);
        $buyer = $this->makeBuyer();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($seller)->delete(route('seller.products.destroy', $product));

        $this->assertDatabaseMissing('cart_items', ['product_id' => $product->id]);
        $this->assertNull(Product::browsable()->find($product->id));
        $this->actingAs($buyer)->get(route('products.show', $product->id))->assertNotFound();
        $this->actingAs($buyer)->get(route('cart.index'))->assertOk();
    }

    public function test_a_seller_cannot_delete_another_sellers_listing(): void
    {
        $owner = $this->makeSeller();
        $intruder = $this->makeSeller();
        $product = $this->makeListing($owner);

        $this->actingAs($intruder)->delete(route('seller.products.destroy', $product))->assertForbidden();

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_an_archived_listing_cannot_be_bought_through_a_stale_cart_request(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $buyer = $this->makeBuyer();
        $product->delete();

        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id])->assertNotFound();
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_every_order_line_and_order_gets_a_valid_enum_status(): void
    {
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller()), 1]]);

        $this->assertSame('pending', $order->status->value);
        $this->assertSame('pending', $order->items->first()->status->value);
        $this->assertInstanceOf(Order::class, $order);
    }
}
