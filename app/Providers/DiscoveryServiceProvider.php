<?php

namespace App\Providers;

use App\Contracts\CatalogueSource;
use App\Contracts\CategoryTree;
use App\Contracts\NotificationPreferences;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Observers\SearchIndexObserver;
use App\Services\Catalogue\CategoryTree as CategoryTreeService;
use App\Services\Discovery\AllowAllNotifications;
use App\Services\Discovery\DatabaseCatalogueSource;
use App\Services\Search\PortableSearch;
use App\Services\Search\PostgresSearch;
use App\Services\Search\QueryUnderstanding;
use App\Services\Search\SearchCapabilities;
use App\Services\Search\SearchEngine;
use App\Services\Search\Synonyms;
use App\Services\Search\Vocabulary;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for search, recommendations, wishlists, alerts, Q&A, review extras and the shopping assistant: container
 * bindings, rate limiters, model observers and the scheduled jobs. Everything that must work with `route:cache`
 * lives here, never in a route file.
 */
class DiscoveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SearchCapabilities::class);
        $this->app->singleton(Synonyms::class);
        $this->app->singleton(Vocabulary::class);
        $this->app->singleton(QueryUnderstanding::class);

        // Defaults for the seams to other workstreams; a later provider can bind its own and these step aside.
        $this->app->singletonIf(CatalogueSource::class, DatabaseCatalogueSource::class);
        // One category tree for the whole app (D-50): the commerce service implements the lookup contract.
        $this->app->singleton(CategoryTreeService::class);
        $this->app->singletonIf(CategoryTree::class, fn ($app) => $app->make(CategoryTreeService::class));
        $this->app->singletonIf(NotificationPreferences::class, AllowAllNotifications::class);

        // The engine is chosen by what the database can do, not by configuration alone.
        $this->app->singleton(SearchEngine::class, function ($app) {
            return $app->make($app->make(SearchCapabilities::class)->fullText() ? PostgresSearch::class : PortableSearch::class);
        });
    }

    public function boot(): void
    {
        $observer = SearchIndexObserver::class;
        Product::observe($observer);
        Store::observe($observer);
        StoreCategory::observe($observer);

        // Typing in the search box is the cheapest request we serve and the easiest to flood.
        RateLimiter::for('suggest', fn (Request $request) => Limit::perMinute(90)->by('suggest:'.($request->user()?->id ?? $request->ip())));

        // The assistant runs a full search (and, with a key, a model call): per person and per address.
        RateLimiter::for('assistant', fn (Request $request) => [
            Limit::perMinute(8)->by('assistant:'.($request->user()?->id ?? $request->ip())),
            Limit::perHour(60)->by('assistant-hour:'.$request->ip()),
        ]);

        // Questions, answers, reports, votes: writes that other people read.
        RateLimiter::for('community', fn (Request $request) => Limit::perMinute(20)->by('community:'.($request->user()?->id ?? $request->ip())));

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Hourly: write the precomputed recommendation links. The lock outlives one run, not a day.
            $schedule->command('emall:recommendations-rebuild')->hourly()->withoutOverlapping(45);
            // Every five minutes: index new and edited listings the observers missed, refresh units sold.
            $schedule->command('emall:search-reindex --stale')->everyFiveMinutes()->withoutOverlapping(10);
            // Stock and price alerts, saved-search digests.
            $schedule->command('emall:alerts-evaluate')->everyFifteenMinutes()->withoutOverlapping(20);
            // Retention: search log 90 days, product views 90 days, events 400 days.
            $schedule->command('emall:discovery-prune')->dailyAt('03:30')->withoutOverlapping(30);
        });
    }
}
