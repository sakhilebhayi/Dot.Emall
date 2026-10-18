<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Lets a test stand the database at "production as it was": every migration
 * older than a given file applied, none of the newer ones. The test then
 * inserts legacy rows with plain DB calls (no models, whose current shape
 * would not match the old schema), runs the real `migrate`, and asserts on
 * what happened to that data.
 *
 * Do not combine with RefreshDatabase: this rebuilds the schema itself.
 */
trait MigratesFromLegacySchema
{
    protected function migrateUpTo(string $firstNewMigration): void
    {
        $paths = collect(glob(database_path('migrations/*.php')))
            ->map(fn ($file) => basename($file))
            ->sort()
            ->filter(fn ($name) => $name < $firstNewMigration)
            ->map(fn ($name) => 'database/migrations/'.$name)
            ->values()
            ->all();

        Artisan::call('migrate:fresh', ['--path' => $paths, '--force' => true]);
    }

    protected function migrateRest(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function insertUser(string $name = 'Legacy User'): int
    {
        static $n = 0;
        $n++;

        return DB::table('users')->insertGetId([
            'name' => $name,
            'email' => "legacy{$n}-".bin2hex(random_bytes(4)).'@example.test',
            'password' => 'x',
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:00:00',
        ]);
    }

    protected function insertStore(int $ownerId, string $name = 'Legacy Store'): int
    {
        return DB::table('stores')->insertGetId([
            'owner_id' => $ownerId,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)),
            'is_active' => true,
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:00:00',
        ]);
    }

    protected function insertProduct(int $storeId, array $attrs = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'store_id' => $storeId,
            'name' => 'Legacy Widget',
            'slug' => 'legacy-widget-'.bin2hex(random_bytes(4)),
            'price' => 10,
            'stock' => 5,
            'is_active' => true,
            'created_at' => '2026-07-01 10:00:00',
            'updated_at' => '2026-07-01 10:00:00',
        ], $attrs));
    }
}
