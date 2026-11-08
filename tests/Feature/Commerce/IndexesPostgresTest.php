<?php

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every new query path has an index PostgreSQL can use (EXPLAIN). On tiny tables the planner prefers a sequential scan
 * whatever indexes exist, so this switches sequential scans off inside the test's transaction: if a query STILL scans the
 * table, there is no index it could have used. Skips on SQLite.
 */
class IndexesPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('EXPLAIN plans are a PostgreSQL matter.');
        }

        DB::statement('SET LOCAL enable_seqscan = off');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>}>
     */
    public static function queries(): array
    {
        return [
            'stock movements of a variant' => ['stock_movements', 'select * from stock_movements where variant_id = ? order by id desc limit 20', [1]],
            'price history of a variant' => ['product_price_history', 'select * from product_price_history where variant_id = ? order by changed_at, id', [1]],
            'category subtree by path prefix' => ['store_categories', "select id from store_categories where path like '/7/%'", []],
            'children of a category in order' => ['store_categories', 'select * from store_categories where parent_id = ? order by sort_order', [1]],
            'newest listed products (the storefront listing)' => ['products', "select * from products where status = 'approved' and is_active = true order by created_at desc limit 12", []],
            'listed products of a category' => ['products', "select id from products where category_id = ? and status = 'approved' and is_active = true", [1]],
            'facet counts per value' => ['product_attributes', "select value, count(*) from product_attributes where key = 'ram' group by value", []],
            'facet numeric range' => ['product_attributes', "select product_id from product_attributes where key = 'ram' and value_number between 8 and 16", []],
            'variants of a product' => ['product_variants', 'select * from product_variants where product_id = ? and is_active = true', [1]],
            'returns due for escalation' => ['return_requests', "select * from return_requests where status = 'requested' and respond_by <= now()", []],
            'a sellers incoming returns' => ['return_requests', 'select * from return_requests where seller_id = ? order by id desc', [1]],
            'ledger entries due for release' => ['ledger_entries', "select * from ledger_entries where kind = 'sale' and status = 'held' and release_at <= now()", []],
            'a sellers ledger balance' => ['ledger_entries', "select status, sum(net_cents) from ledger_entries where seller_id = ? and kind = 'sale' group by status", [1]],
            'a sellers parcels by status' => ['order_groups', "select * from order_groups where seller_id = ? and status = 'pending'", [1]],
            'shipment by tracking number' => ['shipments', "select * from shipments where tracking_number = 'ABC123'", []],
            'shipments of a parcel' => ['shipments', 'select * from shipments where order_group_id = ?', [1]],
            'guest orders of an address' => ['orders', "select id from orders where user_id is null and guest_email = 'a@b.test'", []],
            'shipping methods of a store' => ['shipping_methods', 'select * from shipping_methods where store_id = ? and is_active = true', [1]],
            'status history of an order' => ['order_status_histories', 'select * from order_status_histories where order_id = ? order by id', [1]],
            'a persons addresses' => ['addresses', 'select * from addresses where user_id = ? order by is_default_shipping desc', [1]],
            'a persons cart' => ['cart_items', 'select * from cart_items where user_id = ?', [1]],
        ];
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    #[DataProvider('queries')]
    public function test_the_query_can_use_an_index(string $table, string $sql, array $bindings): void
    {
        $plan = collect(DB::select('EXPLAIN '.$sql, $bindings))->map(fn ($row) => array_values((array) $row)[0])->implode("\n");

        $this->assertStringNotContainsString("Seq Scan on {$table}", $plan, "no index serves this query:\n{$sql}\n{$plan}");
    }
}
