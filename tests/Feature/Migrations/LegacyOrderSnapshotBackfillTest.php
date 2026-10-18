<?php

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MigratesFromLegacySchema;
use Tests\TestCase;

/**
 * Stands the database at the schema before order snapshots existed, loads
 * legacy orders (including the shapes the old code got wrong), runs the real
 * migrations and checks what happened to them.
 */
class LegacyOrderSnapshotBackfillTest extends TestCase
{
    use MigratesFromLegacySchema;

    private int $sellerA;

    private int $sellerB;

    private int $storeA;

    private int $storeB;

    private int $buyer;

    private function seedCatalogue(): array
    {
        $this->migrateUpTo('2026_10_19_100000');

        $this->sellerA = $this->insertUser('Seller Alpha');
        $this->sellerB = $this->insertUser('Seller Bravo');
        $this->buyer = $this->insertUser('Legacy Buyer');
        $this->storeA = $this->insertStore($this->sellerA, 'Alpha Store');
        $this->storeB = $this->insertStore($this->sellerB, 'Bravo Store');

        return [
            'a' => $this->insertProduct($this->storeA, ['name' => 'Alpha Kettle', 'price' => 100, 'status' => 'approved']),
            'b' => $this->insertProduct($this->storeB, ['name' => 'Bravo Lantern', 'price' => 50, 'status' => 'approved']),
        ];
    }

    private function insertOrder(array $attrs = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'user_id' => $this->buyer,
            'status' => 'pending',
            'subtotal' => 150,
            'total' => 150,
            'discount_amount' => 0,
            'shipping_address' => '1 Legacy Road',
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ], $attrs));
    }

    private function insertLine(int $orderId, int $productId, array $attrs = []): int
    {
        return DB::table('order_items')->insertGetId(array_merge([
            'order_id' => $orderId,
            'product_id' => $productId,
            'quantity' => 1,
            'unit_price' => 100,
            'total' => 100,
            'status' => 'pending',
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ], $attrs));
    }

    public function test_legacy_lines_get_name_store_and_seller_snapshots(): void
    {
        $p = $this->seedCatalogue();
        $order = $this->insertOrder();
        $lineA = $this->insertLine($order, $p['a']);
        $lineB = $this->insertLine($order, $p['b'], ['unit_price' => 50, 'total' => 50]);

        $this->migrateRest();

        $a = DB::table('order_items')->find($lineA);
        $b = DB::table('order_items')->find($lineB);

        $this->assertSame('Alpha Kettle', $a->product_name);
        $this->assertSame('Alpha Store', $a->store_name);
        $this->assertSame($this->storeA, (int) $a->store_id);
        $this->assertSame($this->sellerA, (int) $a->seller_id);
        $this->assertSame('Bravo Lantern', $b->product_name);
        $this->assertSame($this->sellerB, (int) $b->seller_id);
    }

    public function test_no_legacy_order_line_is_lost_and_product_delete_now_nulls_instead_of_cascading(): void
    {
        $p = $this->seedCatalogue();
        $order = $this->insertOrder();
        $this->insertLine($order, $p['a']);
        $this->insertLine($order, $p['b'], ['unit_price' => 50, 'total' => 50]);

        $this->migrateRest();

        $this->assertSame(2, DB::table('order_items')->count());

        DB::table('products')->where('id', $p['a'])->delete();

        $this->assertSame(2, DB::table('order_items')->count(), 'deleting a product must not delete order lines');
        $line = DB::table('order_items')->where('product_name', 'Alpha Kettle')->first();
        $this->assertNull($line->product_id);
        $this->assertSame($this->sellerA, (int) $line->seller_id);
    }

    public function test_old_free_string_statuses_fold_into_the_enum_values(): void
    {
        $p = $this->seedCatalogue();
        $o1 = $this->insertOrder(['status' => 'confirmed']);
        $o2 = $this->insertOrder(['status' => 'refunded']);
        $o3 = $this->insertOrder(['status' => 'who-knows']);
        $l1 = $this->insertLine($o1, $p['a'], ['status' => 'confirmed']);
        $l2 = $this->insertLine($o3, $p['a'], ['status' => 'garbage']);

        $this->migrateRest();

        $this->assertSame('processing', DB::table('orders')->find($o1)->status);
        $this->assertSame('cancelled', DB::table('orders')->find($o2)->status);
        $this->assertSame('pending', DB::table('orders')->find($o3)->status);
        $this->assertSame('processing', DB::table('order_items')->find($l1)->status);
        $this->assertSame('pending', DB::table('order_items')->find($l2)->status);
    }

    public function test_an_order_with_a_discount_and_no_cancellation_keeps_its_charged_discount_to_the_cent(): void
    {
        $p = $this->seedCatalogue();
        DB::table('promo_codes')->insert([
            'store_id' => $this->storeA, 'code' => 'LEGACY10', 'discount_type' => 'percent', 'discount_value' => 10,
            'used_count' => 1, 'is_active' => true, 'created_at' => '2026-08-01', 'updated_at' => '2026-08-01',
        ]);
        // Charged 10.00 off, even though the code has since been edited to 50%.
        DB::table('promo_codes')->where('code', 'LEGACY10')->update(['discount_value' => 50]);
        $order = $this->insertOrder(['promo_code' => 'LEGACY10', 'discount_amount' => 10, 'subtotal' => 150, 'total' => 140]);
        $lineA = $this->insertLine($order, $p['a']);
        $lineB = $this->insertLine($order, $p['b'], ['unit_price' => 50, 'total' => 50]);

        $this->migrateRest();

        $o = DB::table('orders')->find($order);
        $this->assertEquals(10, (float) $o->discount_amount);
        $this->assertEquals(140, (float) $o->total);
        $this->assertSame($this->storeA, (int) $o->promo_store_id);
        $this->assertEquals(10, (float) DB::table('order_items')->find($lineA)->discount_amount, 'the whole discount sits on the promo store\'s line');
        $this->assertEquals(0, (float) DB::table('order_items')->find($lineB)->discount_amount);
    }

    public function test_an_order_with_a_cancelled_line_is_re_totalled_without_it(): void
    {
        $p = $this->seedCatalogue();
        DB::table('promo_codes')->insert([
            'store_id' => $this->storeA, 'code' => 'HALFOFF', 'discount_type' => 'percent', 'discount_value' => 50,
            'used_count' => 1, 'is_active' => true, 'created_at' => '2026-08-01', 'updated_at' => '2026-08-01',
        ]);
        // Old code counted the cancelled Bravo line: subtotal 150, discount 50 (50% of 100), total 100.
        $order = $this->insertOrder(['status' => 'pending', 'promo_code' => 'halfoff', 'discount_amount' => 50, 'subtotal' => 150, 'total' => 100]);
        $this->insertLine($order, $p['a']);
        $this->insertLine($order, $p['b'], ['unit_price' => 50, 'total' => 50, 'status' => 'cancelled']);

        $this->migrateRest();

        $o = DB::table('orders')->find($order);
        $this->assertEquals(100, (float) $o->subtotal);
        $this->assertEquals(50, (float) $o->discount_amount);
        $this->assertEquals(50, (float) $o->total);
    }

    public function test_an_order_whose_every_line_was_cancelled_becomes_cancelled_with_zero_totals(): void
    {
        $p = $this->seedCatalogue();
        // The old derivation called an all-cancelled order "shipped".
        $order = $this->insertOrder(['status' => 'shipped']);
        $this->insertLine($order, $p['a'], ['status' => 'cancelled']);
        $this->insertLine($order, $p['b'], ['unit_price' => 50, 'total' => 50, 'status' => 'cancelled']);

        $this->migrateRest();

        $o = DB::table('orders')->find($order);
        $this->assertSame('cancelled', $o->status);
        $this->assertEquals(0, (float) $o->total);
        $this->assertEquals(0, (float) $o->subtotal);
    }

    public function test_a_legacy_discount_whose_promo_code_was_deleted_is_spread_over_all_lines(): void
    {
        $p = $this->seedCatalogue();
        $order = $this->insertOrder(['promo_code' => 'GONE', 'discount_amount' => 15, 'subtotal' => 150, 'total' => 135]);
        $lineA = $this->insertLine($order, $p['a']);
        $lineB = $this->insertLine($order, $p['b'], ['unit_price' => 50, 'total' => 50]);

        $this->migrateRest();

        $shares = [
            (float) DB::table('order_items')->find($lineA)->discount_amount,
            (float) DB::table('order_items')->find($lineB)->discount_amount,
        ];
        $this->assertEquals([10.0, 5.0], $shares);
        $this->assertEquals(135, (float) DB::table('orders')->find($order)->total);
    }

    public function test_the_new_columns_and_indexes_exist_after_migrating_legacy_data(): void
    {
        $this->seedCatalogue();
        $this->migrateRest();

        foreach (['product_name', 'store_id', 'store_name', 'seller_id', 'discount_amount'] as $column) {
            $this->assertTrue(Schema::hasColumn('order_items', $column), $column);
        }
        $this->assertTrue(Schema::hasIndex('order_items', 'order_items_status_index'));
        $this->assertTrue(Schema::hasIndex('order_items', ['seller_id', 'status']));
    }
}
