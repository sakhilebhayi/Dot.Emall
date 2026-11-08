<?php

namespace App\Console\Commands;

use Database\Seeders\EmallDemoSeeder;
use Illuminate\Console\Command;

/**
 * Builds the fictional demo marketplace (users, stores, products, orders in
 * every state, reviews, notifications) for design and audit work. Refuses to
 * run in production. Safe to run twice: without --fresh a second run changes
 * nothing; with --fresh it removes only what it created and rebuilds.
 * See docs/R43_DEMO.md.
 */
class EmallDemoSeed extends Command
{
    protected $signature = 'emall:demo-seed {--fresh : Remove the demo data first and rebuild it}';

    protected $description = 'Seed a believable fictional South African marketplace (never in production)';

    public function handle(EmallDemoSeeder $seeder): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: the demo seeder never runs in production.');

            return self::FAILURE;
        }

        $seeder->setCommand($this);

        if ($this->option('fresh')) {
            $this->info('Removing existing demo data...');
            $seeder->purge();
        }

        $seeder->run();

        return self::SUCCESS;
    }
}
