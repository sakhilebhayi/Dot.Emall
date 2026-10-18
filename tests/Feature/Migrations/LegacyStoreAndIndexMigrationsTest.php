<?php

namespace Tests\Feature\Migrations;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\MigratesFromLegacySchema;
use Tests\TestCase;

class LegacyStoreAndIndexMigrationsTest extends TestCase
{
    use MigratesFromLegacySchema;

    public function test_one_store_per_owner_is_enforced_when_existing_data_allows_it(): void
    {
        $this->migrateUpTo('2026_10_19_300000');
        $owner = $this->insertUser();
        $this->insertStore($owner, 'Only Store');
        $this->insertStore($this->insertUser(), 'Someone Elses Store');

        $this->migrateRest();

        $this->assertSame(2, DB::table('stores')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertStore($owner, 'Second Store');
    }

    public function test_an_owner_who_already_has_several_stores_loses_nothing_and_the_migration_still_succeeds(): void
    {
        $this->migrateUpTo('2026_10_19_300000');
        $owner = $this->insertUser();
        $first = $this->insertStore($owner, 'First Store');
        $second = $this->insertStore($owner, 'Second Store');
        $this->insertProduct($second, ['name' => 'Product In Second Store', 'status' => 'approved']);

        $this->migrateRest();

        $this->assertSame(2, DB::table('stores')->where('owner_id', $owner)->count(), 'no store is deleted or merged');
        $this->assertSame(1, DB::table('products')->count());
        $this->assertFalse(Schema::hasIndex('stores', 'stores_owner_id_unique'), 'the unique rule is skipped, not forced');

        $user = User::find($owner);
        $this->assertSame($first, $user->store->id, 'the oldest store is the one the app treats as theirs');
    }

    public function test_the_listing_indexes_exist_after_migrating(): void
    {
        $this->migrateUpTo('2026_10_19_300000');
        $this->migrateRest();

        $this->assertTrue(Schema::hasIndex('products', 'products_name_index'));
        $this->assertTrue(Schema::hasIndex('products', ['status', 'is_active']));
        $this->assertTrue(Schema::hasIndex('products', ['store_id', 'status']));
        $this->assertTrue(Schema::hasIndex('order_items', 'order_items_status_index'));
    }

    public function test_the_index_migration_is_safe_to_run_twice(): void
    {
        $this->migrateUpTo('2026_10_19_300000');
        $this->migrateRest();

        foreach (glob(database_path('migrations/2026_10_19_3*.php')) as $file) {
            (include $file)->up();
        }

        $this->assertTrue(Schema::hasIndex('products', 'products_name_index'));
    }
}
