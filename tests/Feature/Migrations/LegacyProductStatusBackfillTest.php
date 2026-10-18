<?php

namespace Tests\Feature\Migrations;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesFromLegacySchema;
use Tests\TestCase;

/**
 * 2026_08_09_000002 added products.status DEFAULT 'pending_approval' with no
 * backfill, hiding every pre-existing listing. These tests stand the schema
 * at the state right after that migration, with legacy rows, and prove the
 * backfill migration restores exactly the right ones.
 */
class LegacyProductStatusBackfillTest extends TestCase
{
    use MigratesFromLegacySchema;

    private const BACKFILL = '2026_10_19_100200_backfill_legacy_product_status.php';

    private function seedLegacy(): array
    {
        $this->migrateUpTo('2026_10_19_100000');

        $store = $this->insertStore($this->insertUser());

        return [
            // Existed before the moderation gate shipped: got the column default.
            'legacy' => $this->insertProduct($store, ['created_at' => '2026-07-01 10:00:00']),
            'legacy_no_timestamp' => $this->insertProduct($store, ['created_at' => null]),
            // A genuine seller submission after the gate: must stay pending.
            'submitted_since' => $this->insertProduct($store, ['created_at' => '2026-09-15 12:00:00']),
            // Created the instant after the gate shipped.
            'just_after_gate' => $this->insertProduct($store, ['created_at' => '2026-08-09 07:10:34']),
            // Old row an operator rejected: must stay rejected.
            'rejected' => $this->insertProduct($store, ['created_at' => '2026-07-02 10:00:00', 'status' => 'rejected', 'rejected_reason' => 'Blurry photos']),
            // Old row an operator touched but left pending: review fields prove it is not legacy.
            'reviewed_pending' => $this->insertProduct($store, ['created_at' => '2026-07-03 10:00:00', 'reviewed_at' => '2026-08-20 10:00:00']),
            // Old row already approved.
            'approved' => $this->insertProduct($store, ['created_at' => '2026-07-04 10:00:00', 'status' => 'approved']),
        ];
    }

    public function test_the_old_schema_really_hides_legacy_listings(): void
    {
        $ids = $this->seedLegacy();

        $this->assertSame('pending_approval', DB::table('products')->where('id', $ids['legacy'])->value('status'));
    }

    public function test_pre_gate_listings_become_approved_and_nothing_else_changes(): void
    {
        $ids = $this->seedLegacy();

        $this->migrateRest();

        $status = fn (string $key) => DB::table('products')->where('id', $ids[$key])->value('status');

        $this->assertSame('approved', $status('legacy'));
        $this->assertSame('approved', $status('legacy_no_timestamp'));
        $this->assertSame('pending_approval', $status('submitted_since'), 'a post-gate submission awaits review');
        $this->assertSame('pending_approval', $status('just_after_gate'));
        $this->assertSame('rejected', $status('rejected'));
        $this->assertSame('pending_approval', $status('reviewed_pending'));
        $this->assertSame('approved', $status('approved'));
    }

    public function test_the_storefront_scope_shows_legacy_listings_after_migrating(): void
    {
        $ids = $this->seedLegacy();
        $this->migrateRest();

        $visible = Product::browsable()->pluck('id')->all();

        $this->assertContains($ids['legacy'], $visible);
        $this->assertNotContains($ids['submitted_since'], $visible);
        $this->assertNotContains($ids['rejected'], $visible);
    }

    public function test_running_the_backfill_again_changes_nothing(): void
    {
        $ids = $this->seedLegacy();
        $this->migrateRest();
        $before = DB::table('products')->orderBy('id')->pluck('status', 'id')->all();

        (include database_path('migrations/'.self::BACKFILL))->up();

        $this->assertSame($before, DB::table('products')->orderBy('id')->pluck('status', 'id')->all());
        $this->assertSame(count($ids), DB::table('products')->count());
    }

    public function test_a_fresh_database_with_no_rows_migrates_cleanly(): void
    {
        $this->migrateUpTo('2026_10_19_100000');
        $this->migrateRest();

        $this->assertSame(0, DB::table('products')->count());
    }
}
