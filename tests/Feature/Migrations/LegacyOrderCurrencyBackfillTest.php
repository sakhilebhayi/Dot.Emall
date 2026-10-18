<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\MigratesFromLegacySchema;
use Tests\TestCase;

/**
 * Orders placed before the currency column existed are stamped with the
 * storefront currency in force when the migration runs: the only honest
 * answer available (the old storefront printed a bare symbol and never
 * recorded one), and what an operator who has set SHOP_CURRENCY expects.
 */
class LegacyOrderCurrencyBackfillTest extends TestCase
{
    use MigratesFromLegacySchema;

    private const FIRST = '2026_10_21_100000';

    private function legacyOrder(int $userId, string $status = 'pending', string $total = '50.00'): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => $userId, 'status' => $status, 'subtotal' => $total, 'total' => $total,
            'discount_amount' => 0, 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00',
        ]);
    }

    public function test_every_existing_order_is_stamped_with_the_current_storefront_currency(): void
    {
        $this->migrateUpTo(self::FIRST);
        $this->assertFalse(Schema::hasColumn('orders', 'currency'));
        $user = $this->insertUser();
        $ids = [$this->legacyOrder($user), $this->legacyOrder($user, 'delivered', '75.50'), $this->legacyOrder($user, 'cancelled', '10.00')];

        $this->migrateRest();

        foreach ($ids as $id) {
            $this->assertSame('ZAR', DB::table('orders')->find($id)->currency);
        }
    }

    public function test_the_backfill_uses_the_configured_currency_not_a_hard_coded_one(): void
    {
        config(['shop.currency' => 'EUR']);
        $this->migrateUpTo(self::FIRST);
        $id = $this->legacyOrder($this->insertUser());

        $this->migrateRest();

        $this->assertSame('EUR', DB::table('orders')->find($id)->currency);
    }

    public function test_money_and_status_history_are_untouched(): void
    {
        $this->migrateUpTo(self::FIRST);
        $id = $this->legacyOrder($this->insertUser(), 'delivered', '75.50');
        $before = (array) DB::table('orders')->find($id);

        $this->migrateRest();

        $after = (array) DB::table('orders')->find($id);
        unset($after['currency']);
        $this->assertEquals($before, $after);
    }

    public function test_a_later_config_change_does_not_move_stamped_orders(): void
    {
        $this->migrateUpTo(self::FIRST);
        $id = $this->legacyOrder($this->insertUser());
        $this->migrateRest();

        config(['shop.currency' => 'USD']);

        $this->assertSame('ZAR', DB::table('orders')->find($id)->currency);
    }

    public function test_the_column_is_three_characters_and_never_null(): void
    {
        $this->migrateUpTo(self::FIRST);
        $this->migrateRest();

        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'user_id' => $this->insertUser(), 'status' => 'pending', 'subtotal' => 1, 'total' => 1, 'discount_amount' => 0,
            'currency' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_invalid_currency_setting_stops_the_migration_before_anything_is_written(): void
    {
        $this->migrateUpTo(self::FIRST);
        $id = $this->legacyOrder($this->insertUser());
        config(['shop.currency' => 'dollars']);

        try {
            $this->migrateRest();
            $this->fail('the migration must refuse an invalid SHOP_CURRENCY');
        } catch (RuntimeException|\InvalidArgumentException $e) {
            $this->assertStringContainsString('SHOP_CURRENCY', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('orders', 'currency'), 'nothing may be half-applied');
        $this->assertSame(1, DB::table('orders')->where('id', $id)->count());
    }

    public function test_rolling_back_drops_only_the_column(): void
    {
        $this->migrateUpTo(self::FIRST);
        $id = $this->legacyOrder($this->insertUser());
        $this->migrateRest();

        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $this->assertFalse(Schema::hasColumn('orders', 'currency'));
        $this->assertSame(1, DB::table('orders')->where('id', $id)->count());
    }

    public function test_running_the_migrations_twice_is_harmless(): void
    {
        $this->migrateUpTo(self::FIRST);
        $id = $this->legacyOrder($this->insertUser());
        $this->migrateRest();
        $this->migrateRest();

        $this->assertSame('ZAR', DB::table('orders')->find($id)->currency);
    }
}
