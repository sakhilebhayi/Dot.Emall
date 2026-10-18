<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesFromLegacySchema;
use Tests\TestCase;

/**
 * Promo codes were unique across the whole marketplace (a seller could
 * squat on, or collide with, another store's code); reviews had no stored
 * rating aggregate. Both are changed in place on a database that already
 * holds rows.
 */
class LegacyCheckoutSafetyMigrationsTest extends TestCase
{
    use MigratesFromLegacySchema;

    private function promo(int $storeId, string $code, array $extra = []): int
    {
        return DB::table('promo_codes')->insertGetId(array_merge([
            'store_id' => $storeId, 'code' => $code, 'discount_type' => 'percent', 'discount_value' => 10,
            'used_count' => 0, 'is_active' => true, 'created_at' => '2026-08-01', 'updated_at' => '2026-08-01',
        ], $extra));
    }

    public function test_legacy_promo_codes_survive_are_upper_cased_and_become_unique_per_store(): void
    {
        $this->migrateUpTo('2026_10_19_200000');
        $storeA = $this->insertStore($this->insertUser());
        $storeB = $this->insertStore($this->insertUser());
        $lower = $this->promo($storeA, 'welcome10', ['used_count' => 4]);
        $upper = $this->promo($storeB, 'KEEPME');

        $this->migrateRest();

        $this->assertSame('WELCOME10', DB::table('promo_codes')->find($lower)->code);
        $this->assertSame(4, (int) DB::table('promo_codes')->find($lower)->used_count, 'usage history is untouched');
        $this->assertSame('KEEPME', DB::table('promo_codes')->find($upper)->code);

        // Now another store may use the same code, the same store may not.
        $this->promo($storeB, 'WELCOME10');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->promo($storeA, 'WELCOME10');
    }

    public function test_two_legacy_codes_in_one_store_that_differ_only_by_case_are_not_merged_or_lost(): void
    {
        // Possible only if rows were inserted by hand: the controller always upper-cased.
        $this->migrateUpTo('2026_10_19_200000');
        $store = $this->insertStore($this->insertUser());
        $a = $this->promo($store, 'Dup');
        $b = $this->promo($store, 'DUP');

        $this->migrateRest();

        $this->assertSame(2, DB::table('promo_codes')->count());
        $this->assertNotSame(DB::table('promo_codes')->find($a)->code, DB::table('promo_codes')->find($b)->code);
    }

    public function test_review_aggregates_are_backfilled_from_existing_reviews(): void
    {
        $this->migrateUpTo('2026_10_19_200000');
        $store = $this->insertStore($this->insertUser());
        $reviewed = $this->insertProduct($store, ['status' => 'approved']);
        $unreviewed = $this->insertProduct($store, ['status' => 'approved']);
        foreach ([5, 4, 4] as $rating) {
            DB::table('reviews')->insert([
                'product_id' => $reviewed, 'user_id' => $this->insertUser(), 'rating' => $rating,
                'created_at' => '2026-08-01', 'updated_at' => '2026-08-01',
            ]);
        }

        $this->migrateRest();

        $p = DB::table('products')->find($reviewed);
        $this->assertSame(3, (int) $p->rating_count);
        $this->assertEquals(4.33, (float) $p->rating_average);
        $this->assertSame(0, (int) DB::table('products')->find($unreviewed)->rating_count);
        $this->assertEquals(0, (float) DB::table('products')->find($unreviewed)->rating_average);
    }

    public function test_existing_orders_get_a_null_token_and_stay_valid(): void
    {
        $this->migrateUpTo('2026_10_19_200000');
        $buyer = $this->insertUser();
        DB::table('orders')->insert(['user_id' => $buyer, 'status' => 'pending', 'subtotal' => 1, 'total' => 1, 'created_at' => '2026-08-01', 'updated_at' => '2026-08-01']);
        DB::table('orders')->insert(['user_id' => $buyer, 'status' => 'pending', 'subtotal' => 1, 'total' => 1, 'created_at' => '2026-08-01', 'updated_at' => '2026-08-01']);

        $this->migrateRest();

        $this->assertSame(2, DB::table('orders')->whereNull('idempotency_key')->count());
    }
}
