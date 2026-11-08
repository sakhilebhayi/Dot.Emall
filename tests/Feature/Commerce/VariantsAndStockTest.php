<?php

namespace Tests\Feature\Commerce;

use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Catalogue\Inventory;
use App\Services\Catalogue\ProductVariants;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\OrderLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class VariantsAndStockTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function shirt(array $attributes = []): array
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, array_merge(['name' => 'Cotton Overshirt Unique', 'price' => 100, 'stock' => 0], $attributes));
        $service = app(ProductVariants::class);
        $service->defineOptions($product, ['Size' => ['S', 'M', 'L'], 'Colour' => ['Red', 'Blue']]);
        $m = $service->saveVariant($product, ['Size' => 'M', 'Colour' => 'Red'], ['price' => 120, 'stock' => 5, 'sku' => 'SHIRT-M-RED']);
        $l = $service->saveVariant($product, ['Size' => 'L', 'Colour' => 'Red'], ['price' => 140, 'stock' => 2]);

        return [$seller, $product->fresh(), $m, $l];
    }

    public function test_every_product_gets_one_implicit_default_variant_that_mirrors_it(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 49.5, 'stock' => 7, 'sku' => 'PLAIN-1']);

        $variants = $product->variants;

        $this->assertCount(1, $variants);
        $this->assertTrue($variants->first()->is_default);
        $this->assertNull($variants->first()->label());
        $this->assertSame('49.50', $variants->first()->price);
        $this->assertSame(7, $variants->first()->stock);
        $this->assertSame('PLAIN-1', $variants->first()->sku);
        $this->assertSame(1, StockMovement::query()->where('variant_id', $variants->first()->id)->where('reason', 'initial')->count());
    }

    public function test_writing_price_stock_or_sku_on_the_product_reaches_the_single_variant(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 10, 'stock' => 4]);

        $product->update(['price' => 12.5, 'stock' => 9, 'sku' => 'NEW-SKU']);

        $variant = $product->defaultVariant()->first();
        $this->assertSame('12.50', $variant->price);
        $this->assertSame(9, $variant->stock);
        $this->assertSame('NEW-SKU', $variant->sku);
        $this->assertSame(5, $variant->stockMovements()->where('reason', 'adjustment')->value('delta'));
    }

    public function test_writing_on_the_variant_reaches_the_product(): void
    {
        $product = $this->makeListing($this->makeSeller(), ['price' => 10, 'stock' => 4]);

        $product->defaultVariant()->first()->update(['price' => 8, 'stock' => 1]);

        $this->assertSame('8.00', $product->fresh()->price);
        $this->assertSame(1, $product->fresh()->stock);
    }

    public function test_a_product_with_options_shows_its_cheapest_price_and_total_stock(): void
    {
        [, $product, $m, $l] = $this->shirt();

        $this->assertSame('120.00', $product->price);
        $this->assertSame(7, $product->stock);
        $this->assertSame(['Size' => 'M', 'Colour' => 'Red'], $m->options);
        $this->assertSame('Size: M, Colour: Red', $m->label());
        $this->assertSame(2, $product->variants()->count(), 'the implicit variant is retired once real options exist');

        $l->update(['is_active' => false]);
        $this->assertSame(5, $product->fresh()->stock, 'inactive variants do not count');
    }

    public function test_variant_choices_are_validated_and_unique(): void
    {
        [, $product] = $this->shirt();
        $service = app(ProductVariants::class);

        foreach ([['Size' => 'XXL', 'Colour' => 'Red'], ['Size' => 'M'], []] as $bad) {
            try {
                $service->saveVariant($product, $bad, ['price' => 1]);
                $this->fail('expected a validation error');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Saving the same combination again updates it, it does not add a second.
        $service->saveVariant($product, ['Size' => 'M', 'Colour' => 'Red'], ['price' => 125]);
        $this->assertSame(2, $product->variants()->count());
        $this->assertSame('125.00', ProductVariant::query()->where('sku', 'SHIRT-M-RED')->value('price'));
    }

    public function test_checkout_prices_and_takes_stock_from_the_chosen_variant(): void
    {
        [, , $m, $l] = $this->shirt();
        $buyer = $this->makeBuyer();

        $order = $this->placeOrderForVariants($buyer, [[$m, 2], [$l, 1]]);

        $this->assertSame(380 * 1.0, (float) $order->total);
        $line = $order->items->firstWhere('variant_id', $m->id);
        $this->assertSame('Size: M, Colour: Red', $line->variant_label);
        $this->assertSame('SHIRT-M-RED', $line->sku);
        $this->assertSame('120.00', $line->unit_price);
        $this->assertSame(3, $m->fresh()->stock);
        $this->assertSame(1, $l->fresh()->stock);
        $this->assertSame(4, $line->product->fresh()->stock);
        $this->assertStringContainsString('(Size: M, Colour: Red)', $line->displayName());

        $movement = StockMovement::query()->where('variant_id', $m->id)->where('reason', 'sale')->firstOrFail();
        $this->assertSame([-2, 5, 3], [$movement->delta, $movement->stock_before, $movement->stock_after]);
        $this->assertSame($buyer->id, $movement->actor_id);
        $this->assertSame($order->id, $movement->order_id);
    }

    public function test_one_variants_stock_does_not_pay_for_anothers(): void
    {
        [, , $m, $l] = $this->shirt();

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('Only 2 of "Cotton Overshirt Unique (Size: L, Colour: Red)" left');

        $this->placeOrderForVariants($this->makeBuyer(), [[$l, 3]]);
    }

    public function test_the_last_unit_of_a_variant_can_only_be_bought_once(): void
    {
        [, , $m] = $this->shirt();
        $m->update(['stock' => 1]);
        $a = $this->makeBuyer();
        $b = $this->makeBuyer();

        $this->placeOrderForVariants($a, [[$m, 1]]);

        try {
            $this->placeOrderForVariants($b, [[$m, 1]]);
            $this->fail('the second buyer must be refused');
        } catch (CheckoutException $e) {
            $this->assertStringContainsString('out of stock', $e->getMessage());
        }

        $this->assertSame(0, $m->fresh()->stock);
        $this->assertSame(0, CartItem::withoutGlobalScopes()->where('user_id', $a->id)->count());
    }

    public function test_cancelling_a_line_puts_the_units_back_on_that_variant_with_a_movement(): void
    {
        [, , $m] = $this->shirt();
        $buyer = $this->makeBuyer();
        $order = $this->placeOrderForVariants($buyer, [[$m, 3]]);

        app(OrderLifecycle::class)->cancelItem($order->items->first(), $buyer);
        app(OrderLifecycle::class)->cancelItem($order->items->first(), $buyer); // a replay restocks nothing

        $this->assertSame(5, $m->fresh()->stock);
        $this->assertSame(7, $m->product->fresh()->stock, 'the product shows the total again');
        $this->assertSame(1, StockMovement::query()->where('variant_id', $m->id)->where('reason', 'cancellation')->count());
    }

    public function test_a_cart_holds_one_line_per_variant_not_per_product(): void
    {
        [, $product, $m, $l] = $this->shirt();
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'variant_id' => $m->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'variant_id' => $l->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->actingAs($buyer)->post(route('cart.store'), ['product_id' => $product->id, 'variant_id' => $m->id, 'quantity' => 1])->assertSessionHasNoErrors();

        $lines = CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->orderBy('variant_id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([2, 1], $lines->pluck('quantity')->all());
    }

    public function test_a_product_with_options_cannot_be_added_without_choosing_one(): void
    {
        [, $product] = $this->shirt();

        $this->actingAs($this->makeBuyer())->post(route('cart.store'), ['product_id' => $product->id])->assertSessionHasErrors('variant_id');
        $this->actingAs($this->makeBuyer())->post(route('cart.store'), ['product_id' => $product->id, 'variant_id' => ProductVariant::factory()->create()->id])->assertNotFound();
    }

    public function test_the_seller_stock_edit_is_a_delta_against_the_number_the_form_showed(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 5, 'price' => 20]);
        $buyer = $this->makeBuyer();

        // The form was drawn with 5. A buyer then takes 3.
        $this->placeOrder($buyer, [[$product, 3]]);
        $this->assertSame(2, $product->fresh()->stock);

        $this->actingAs($seller)->patch(route('seller.products.update', $product), [
            'name' => $product->name, 'price' => 25, 'stock' => 5, 'stock_was' => 5,
        ])->assertRedirect();

        $this->assertSame(2, $product->fresh()->stock, 'a price-only edit does not put sold units back');
        $this->assertSame('25.00', $product->fresh()->price);

        // The seller really adds 10: typed 15 over a form that showed 5.
        $this->actingAs($seller)->patch(route('seller.products.update', $product), [
            'name' => $product->name, 'price' => 25, 'stock' => 15, 'stock_was' => 5,
        ]);

        $this->assertSame(12, $product->fresh()->stock);
        $this->assertSame(10, StockMovement::query()->where('product_id', $product->id)->where('reason', 'seller_edit')->value('delta'));
    }

    public function test_the_low_stock_threshold_is_the_sellers_to_set(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['stock' => 20, 'price' => 20]);

        $this->assertSame(5, $product->fresh()->low_stock_threshold);

        $this->actingAs($seller)->patch(route('seller.products.update', $product), [
            'name' => $product->name, 'price' => 20, 'stock' => 20, 'stock_was' => 20, 'low_stock_threshold' => 12,
        ]);

        $this->assertSame(12, $product->fresh()->low_stock_threshold);
        $this->assertTrue($product->defaultVariant()->first()->fresh()->isLow() === false);
    }

    public function test_low_stock_lists_variants_at_or_below_their_products_own_threshold(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['stock' => 3, 'low_stock_threshold' => 5]);
        $this->makeListing($seller, ['stock' => 8, 'low_stock_threshold' => 5]);
        $c = $this->makeListing($seller, ['stock' => 8, 'low_stock_threshold' => 10]);
        $this->makeListing($seller, ['stock' => 1, 'low_stock_threshold' => 5])->delete();
        $this->makeListing($this->makeSeller(), ['stock' => 0]);

        $low = app(Inventory::class)->lowStock($seller->store);

        $this->assertSame([$a->id, $c->id], $low->pluck('product_id')->all());
    }

    public function test_inventory_never_goes_below_zero_and_logs_every_change(): void
    {
        $variant = $this->makeListing($this->makeSeller(), ['stock' => 3])->defaultVariant()->first();
        $inventory = app(Inventory::class);

        $inventory->take($variant, 2);

        try {
            $inventory->take($variant->fresh(), 2);
            $this->fail('should be refused');
        } catch (CheckoutException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, $variant->fresh()->stock);
        $this->assertSame(1, $variant->fresh()->stock_version, 'one bump per stock change');
        $this->assertSame([3, 1], [StockMovement::query()->where('variant_id', $variant->id)->orderBy('id')->first()->stock_after, StockMovement::query()->where('variant_id', $variant->id)->orderByDesc('id')->first()->stock_after]);
    }

    public function test_a_variant_that_was_ordered_is_deactivated_not_deleted(): void
    {
        [, , $m, $l] = $this->shirt();
        $this->placeOrderForVariants($this->makeBuyer(), [[$m, 1]]);
        $service = app(ProductVariants::class);

        $service->remove($m);
        $service->remove($l);

        $this->assertFalse($m->fresh()->is_active);
        $this->assertNull(ProductVariant::query()->find($l->id), 'an unordered variant is simply removed');
        $this->assertSame(1, OrderItem::query()->where('variant_id', $m->id)->count());
    }

    /**
     * @param  array<int, array{0: ProductVariant, 1: int}>  $lines
     */
    private function placeOrderForVariants($buyer, array $lines, array $input = [])
    {
        foreach ($lines as [$variant, $quantity]) {
            CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => $quantity]);
        }

        return app(CheckoutService::class)->place($buyer, array_merge([
            'shipping_name' => 'Thandiwe Mokoena', 'shipping_address' => '14 Protea Lane, Parkhurst, 2193', 'shipping_phone' => '+27 82 555 0142',
        ], $input))->load('items');
    }
}
