<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MigratesFromLegacySchema;
use Tests\TestCase;

/**
 * Orders that exist before payments do must come through unchanged and
 * stay fulfillable: they are "unpaid" in the honest sense that no payment
 * was ever collected, but they do not require one.
 */
class LegacyPaymentMigrationsTest extends TestCase
{
    use MigratesFromLegacySchema;

    private function legacyOrder(int $userId, string $status = 'pending'): int
    {
        return DB::table('orders')->insertGetId([
            'user_id' => $userId, 'status' => $status, 'subtotal' => 50, 'total' => 50,
            'discount_amount' => 0, 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00',
        ]);
    }

    public function test_existing_orders_become_unpaid_and_do_not_require_payment(): void
    {
        $this->migrateUpTo('2026_10_20_100000');
        $user = $this->insertUser();
        $pending = $this->legacyOrder($user);
        $delivered = $this->legacyOrder($user, 'delivered');

        $this->migrateRest();

        foreach ([$pending, $delivered] as $id) {
            $row = DB::table('orders')->find($id);
            $this->assertSame('unpaid', $row->payment_status);
            $this->assertEquals(0, $row->requires_payment);
            $this->assertNull($row->paid_at);
        }
        $this->assertSame('delivered', DB::table('orders')->find($delivered)->status, 'status history is untouched');
        $this->assertSame(0, DB::table('payments')->count(), 'no payment is invented for old orders');
    }

    public function test_the_payment_tables_enforce_their_uniqueness_rules(): void
    {
        $this->migrateUpTo('2026_10_20_100000');
        $order = $this->legacyOrder($this->insertUser());
        $this->migrateRest();

        $row = fn (string $ref, ?string $txn) => DB::table('payments')->insert([
            'order_id' => $order, 'provider' => 'paystack', 'reference' => $ref, 'amount_cents' => 5000,
            'currency' => 'ZAR', 'status' => 'pending', 'provider_transaction_id' => $txn,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // many attempts with no provider id yet is normal
        $row('EMALL-1-a', null);
        $row('EMALL-1-b', null);
        $row('EMALL-1-c', '900');

        foreach ([['EMALL-1-a', null], ['EMALL-1-d', '900']] as [$ref, $txn]) {
            try {
                $row($ref, $txn);
                $this->fail("duplicate ({$ref}, {$txn}) should have been refused");
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_migration_can_be_rolled_back(): void
    {
        $this->migrateUpTo('2026_10_20_100000');
        $this->migrateRest();

        Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true]);

        $this->assertFalse(Schema::hasTable('payments'));
        $this->assertFalse(Schema::hasColumn('orders', 'payment_status'));
    }
}
