<?php

namespace Tests\Feature\Marketplace;

use App\Models\CartItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class CartStockTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    public function test_adding_more_than_is_in_stock_is_refused_with_the_available_count(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Last Few Enamel Mugs', 'stock' => 3]);
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)
            ->from(route('products.show', $product))
            ->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 4])
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHasErrors('quantity');

        $this->assertStringContainsString('Only 3', session('errors')->first('quantity'));
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_repeated_adds_cannot_creep_past_the_stock(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3]);
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasErrors('quantity');

        $this->assertSame(2, CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->value('quantity'));
    }

    public function test_an_out_of_stock_product_cannot_be_added(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 0]);

        $this->actingAs($this->makeBuyer())
            ->post(route('cart.store'), ['product_id' => $product->id])
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_seller_cannot_put_their_own_listing_in_their_cart(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $this->actingAs($seller)
            ->post(route('cart.store'), ['product_id' => $product->id])
            ->assertSessionHasErrors('product_id');

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_a_buyer_can_change_a_cart_quantity_within_stock(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        $buyer = $this->makeBuyer();
        $item = CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer)->patch(route('cart.update', $item), ['quantity' => 5])->assertRedirect(route('cart.index'))->assertSessionHasNoErrors();

        $this->assertSame(5, $item->fresh()->quantity);
    }

    public function test_a_quantity_update_beyond_stock_is_refused_and_leaves_the_cart_alone(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 5]);
        $buyer = $this->makeBuyer();
        $item = CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 2]);

        $this->actingAs($buyer)->patch(route('cart.update', $item), ['quantity' => 6])->assertSessionHasErrors('quantity');

        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_a_quantity_must_be_a_whole_number_between_one_and_ninety_nine(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['stock' => 500]);
        $buyer = $this->makeBuyer();
        $item = CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 2]);

        foreach ([0, -1, 100, 'abc', 1.5] as $bad) {
            $this->actingAs($buyer)->patch(route('cart.update', $item), ['quantity' => $bad])->assertSessionHasErrors('quantity');
        }

        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_another_users_cart_line_cannot_be_updated(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $owner = $this->makeBuyer();
        $item = CartItem::withoutGlobalScopes()->create(['user_id' => $owner->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($this->makeBuyer())->patch(route('cart.update', $item), ['quantity' => 2])->assertNotFound();

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_the_cart_page_warns_about_lines_that_no_longer_fit_and_blocks_checkout(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Shrinking Stock Ceramic Jug', 'stock' => 5]);
        $buyer = $this->makeBuyer();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 4]);
        $product->update(['stock' => 1]);

        $page = $this->actingAs($buyer)->get(route('cart.index'))->assertOk();

        $page->assertSee('Only 1 left');
        $page->assertSee('Fix the items marked above before placing your order');
        $page->assertDontSee('>Place order<', false);
    }

    public function test_the_cart_page_offers_a_single_use_checkout_token(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $buyer = $this->makeBuyer();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $first = $this->actingAs($buyer)->get(route('cart.index'))->getContent();
        $second = $this->actingAs($buyer)->get(route('cart.index'))->getContent();

        preg_match('/name="checkout_token" value="([0-9a-f-]{36})"/', $first, $a);
        preg_match('/name="checkout_token" value="([0-9a-f-]{36})"/', $second, $b);
        $this->assertNotEmpty($a[1] ?? null, 'a token is rendered');
        $this->assertNotSame($a[1], $b[1], 'a fresh token per render');
    }

    public function test_checkout_reports_the_current_stock_when_it_changed_after_adding(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['name' => 'Contested Soapstone Bowl', 'stock' => 5]);
        $buyer = $this->makeBuyer();
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 4]);
        $product->update(['stock' => 2]);

        $this->actingAs($buyer)->post(route('cart.checkout'), [
            'checkout_token' => (string) Str::uuid(),
            'shipping_address' => '1 Main St',
        ])->assertRedirect(route('cart.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Only 2 of "Contested Soapstone Bowl"') && str_contains($m, '4 in your cart'));

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, $product->fresh()->stock);
    }
}
