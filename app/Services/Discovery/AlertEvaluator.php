<?php

namespace App\Services\Discovery;

use App\Contracts\NotificationPreferences;
use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\SavedSearch;
use App\Notifications\BackInStockNotification;
use App\Notifications\PriceDropNotification;
use App\Notifications\SavedSearchDigestNotification;
use Illuminate\Support\Facades\DB;

/**
 * Looks at every active alert and saved search and sends what is due. Safe to run any number of times, concurrently
 * or twice in a row: each event is claimed by a conditional UPDATE before anything is sent, and only the run whose
 * UPDATE changed a row sends. One alert gives one notice per event:
 *
 *   back in stock   once; the alert is then spent (active = false)
 *   price drop      once per new low: it fires when the live price is under the baseline (and the target), and again
 *                   only if the price later falls under the price it last reported
 *   saved search    at most daily or weekly, only when there are new matches
 */
class AlertEvaluator
{
    public function __construct(
        private readonly ProductAlerts $alerts,
        private readonly SavedSearches $searches,
        private readonly NotificationPreferences $preferences,
    ) {}

    /** @return array{back_in_stock: int, price_drop: int, saved_search: int} */
    public function run(): array
    {
        return [
            'back_in_stock' => $this->backInStock(),
            'price_drop' => $this->priceDrops(),
            'saved_search' => $this->savedSearches(),
        ];
    }

    public function backInStock(): int
    {
        $sent = 0;

        ProductAlert::query()->where('kind', ProductAlert::BACK_IN_STOCK)->where('active', true)
            ->whereIn('product_id', Product::query()->browsable()->where('stock', '>', 0)->select('products.id'))
            ->with(['user', 'product.store'])->orderBy('id')
            ->chunkById(100, function ($alerts) use (&$sent) {
                foreach ($alerts as $alert) {
                    $claimed = ProductAlert::query()->whereKey($alert->id)->where('active', true)
                        ->update(['active' => false, 'triggered_at' => now()]);

                    if ($claimed === 1 && $alert->user && $this->allowed($alert, 'back_in_stock')) {
                        $alert->user->notify(new BackInStockNotification($alert->product));
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    public function priceDrops(): int
    {
        $sent = 0;

        ProductAlert::query()->where('kind', ProductAlert::PRICE_DROP)->where('active', true)->whereNotNull('baseline_price')
            ->whereIn('product_id', Product::query()->browsable()->select('products.id'))
            ->with(['user', 'product.store'])->orderBy('id')
            ->chunkById(100, function ($alerts) use (&$sent) {
                foreach ($alerts as $alert) {
                    $price = (float) $alert->product->price;
                    $baseline = (float) $alert->baseline_price;
                    $limit = $alert->target_price !== null ? min($baseline, (float) $alert->target_price) : $baseline;

                    $due = $alert->target_price !== null ? $price <= $limit : $price < $baseline;

                    if (! $due) {
                        continue;
                    }

                    // Claim this price: only if nothing at or below it has been reported yet.
                    $claimed = ProductAlert::query()->whereKey($alert->id)->where('active', true)
                        ->where(fn ($q) => $q->whereNull('last_notified_price')->orWhere('last_notified_price', '>', $price))
                        ->update(['last_notified_price' => $price, 'triggered_at' => now()]);

                    if ($claimed === 1 && $alert->user && $this->allowed($alert, 'price_drop')) {
                        $alert->user->notify(new PriceDropNotification($alert->product, $baseline, $price, $this->alerts->lowestRecentPrice($alert->product)));
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    public function savedSearches(): int
    {
        $sent = 0;

        SavedSearch::query()->whereIn('notify_frequency', ['daily', 'weekly'])->with('user')->orderBy('id')->chunkById(100, function ($searches) use (&$sent) {
            foreach ($searches as $saved) {
                $every = $saved->notify_frequency === 'daily' ? 23 : 24 * 7 - 1;

                if ($saved->last_notified_at !== null && $saved->last_notified_at->gt(now()->subHours($every))) {
                    continue;
                }

                $new = $this->searches->newMatches($saved);

                if ($new === [] || $saved->user === null) {
                    continue;
                }

                // Claim the window first, so a concurrent run cannot send the same digest.
                $claimed = DB::table('saved_searches')->where('id', $saved->id)
                    ->where(fn ($q) => $q->whereNull('last_notified_at')->orWhere('last_notified_at', '<=', now()->subHours($every)))
                    ->update(['last_notified_at' => now()]);

                if ($claimed !== 1) {
                    continue;
                }

                $this->searches->markSeen($saved, $new);

                if ($this->preferences->allows($saved->user, 'saved_search', 'database')) {
                    $saved->user->notify(new SavedSearchDigestNotification($saved, count($new), array_slice($new, 0, 3)));
                    $sent++;
                }
            }
        });

        return $sent;
    }

    private function allowed(ProductAlert $alert, string $category): bool
    {
        return $alert->user !== null && $this->preferences->allows($alert->user, $category, 'database');
    }
}
