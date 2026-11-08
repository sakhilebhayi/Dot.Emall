<?php

namespace Tests\Feature\Commerce;

use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ShippingMethod;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartQuote;
use App\Services\Catalogue\CategoryTree;
use App\Services\Ledger\LedgerService;
use App\Services\OrderAccess;
use App\Services\Returns\ReturnService;
use App\Services\Shipping\Region;
use App\Services\Shipping\ShippingQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * N+1 guards: each read method of the new services costs the same number of queries for 2 rows as for 20. A method that
 * starts querying per row fails here before it reaches a storefront page.
 */
class QueryCountTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    /** @return array{0: int, 1: int} queries with the small and the large data set */
    private function measure(callable $grow, callable $read, int $small = 2, int $large = 20): array
    {
        $grow($small);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        $a = count(DB::getQueryLog());
        DB::disableQueryLog();

        $grow($large - $small);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        $b = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$a, $b];
    }

    public function test_the_cart_for_an_account_and_for_a_guest(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $cart = app(CartManager::class);

        [$small, $large] = $this->measure(
            function (int $n) use ($seller, $buyer) {
                foreach (range(1, $n) as $i) {
                    $p = $this->makeListing($seller);
                    CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $p->id, 'quantity' => 1]);
                }
            },
            fn () => $cart->lines($buyer)->each(fn ($l) => [$l->product->store->name, $l->product->images, $l->variant->price]),
        );
        $this->assertSame($small, $large, 'account cart lines');

        [$small, $large] = $this->measure(
            function (int $n) use ($seller, $cart) {
                foreach (range(1, $n) as $i) {
                    $cart->add(null, $this->makeListing($seller)->defaultVariant()->first(), 1);
                }
            },
            fn () => $cart->lines(null)->each(fn ($l) => [$l->product->store->name, $l->product->images]),
        );
        $this->assertSame($small, $large, 'guest cart lines');
    }

    public function test_the_cart_quote_with_several_sellers_and_methods(): void
    {
        $buyer = $this->makeBuyer();
        $cart = app(CartManager::class);
        $quote = app(CartQuote::class);
        $address = Address::factory()->create(['user_id' => $buyer->id]);

        [$small, $large] = $this->measure(
            function (int $n) use ($buyer) {
                foreach (range(1, $n) as $i) {
                    $seller = $this->makeSeller();
                    $this->withDelivery($seller);
                    $this->withDelivery($seller, ['name' => 'Overnight', 'price' => 99]);
                    CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $this->makeListing($seller)->id, 'quantity' => 1]);
                }
            },
            function () use ($quote, $cart, $buyer, $address) {
                $result = $quote->quote($cart->lines($buyer), Region::from($address));
                $this->assertTrue($result->totalCents > 0);
            },
        );

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(12, $large);
    }

    public function test_shipping_quotes_across_many_stores(): void
    {
        [$small, $large] = $this->measure(
            function (int $n) {
                foreach (range(1, $n) as $i) {
                    $this->withDelivery($this->makeSeller());
                }
            },
            fn () => app(ShippingQuote::class)->forStores(Order::query()->toBase()->newQuery()->from('stores')->pluck('id')->mapWithKeys(fn ($id) => [$id => 1000])->all(), new Region('ZA', 'Gauteng', '2000')),
        );

        $this->assertSame($small, $large);
        $this->assertSame(ShippingMethod::query()->count(), 20);
    }

    public function test_the_category_tree_and_its_counts(): void
    {
        $seller = $this->makeSeller();
        $tree = app(CategoryTree::class);

        [$small, $large] = $this->measure(
            function (int $n) use ($tree, $seller) {
                foreach (range(1, $n) as $i) {
                    $root = $tree->create(['name' => "Root {$i}".uniqid()]);
                    $child = $tree->create(['name' => "Child {$i}".uniqid()], $root);
                    $this->makeListing($seller, ['category_id' => $child->id]);
                }
            },
            function () use ($tree) {
                CategoryTree::forgetCounts();
                $tree->tree();
            },
        );

        $this->assertSame($small, $large);
    }

    public function test_the_order_page_data_for_each_viewer(): void
    {
        $seller = $this->makeSeller();
        $this->withDelivery($seller);
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$this->makeListing($seller), 1]]);
        $group = $order->groups()->first();
        $access = app(OrderAccess::class);

        foreach ([$buyer, $seller] as $viewer) {
            [$small, $large] = $this->measure(
                function (int $n) use ($order, $group, $seller) {
                    foreach (range(1, $n) as $i) {
                        OrderItem::factory()->forProduct($this->makeListing($seller))->create(['order_id' => $order->id, 'order_group_id' => $group->id]);
                    }
                },
                fn () => $access->present($order->fresh(), $viewer)['lines']->each(fn ($l) => [$l->displayName(), $l->product?->name]),
            );

            $this->assertSame($small, $large, 'order page data for '.($viewer->id === $buyer->id ? 'the buyer' : 'the seller'));
        }
    }

    public function test_the_ledger_balance_and_the_returns_lists(): void
    {
        $this->enablePaystack();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();

        [$small, $large] = $this->measure(
            function (int $n) use ($seller, $buyer) {
                foreach (range(1, $n) as $i) {
                    $order = $this->placeOrder($buyer, [[$this->makeListing($seller, ['stock' => 100]), 1]]);
                    $this->payOrder($order, (string) (5000 + $order->id));
                    $this->deliver($order);
                    app(ReturnService::class)->request($order->items()->first()->fresh(), $buyer, 'faulty');
                }
            },
            function () use ($seller) {
                app(LedgerService::class)->balance($seller);
                app(ReturnService::class)->forSeller($seller)->get()->each(fn ($r) => $r->item->displayName());
                app(ReturnService::class)->operatorQueue()->get();
            },
            small: 2,
            large: 6,
        );

        $this->assertSame($small, $large);
    }
}
