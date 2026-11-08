<?php

/*
|--------------------------------------------------------------------------
| Dot.Analytics (daily aggregate snapshots)
|--------------------------------------------------------------------------
| Once a day (emall:publish-metrics) this app posts, server to server, one
| signed snapshot of aggregate numbers per company to Dot.Analytics
| (POST /api/ingest/snapshots), queued. Both the URL and the secret must be
| set or nothing is sent. The secret is this app's ANALYTICS_INGEST_SECRET_<KEY>
| value in Dot.Analytics' .env. Aggregates only: counts and integer cents.
*/

return [
    'url' => env('ANALYTICS_INGEST_URL'),
    'secret' => env('ANALYTICS_INGEST_SECRET'),

    // Dot.Analytics' name for this app (it picks the signing secret by this key).
    'platform' => env('ANALYTICS_PLATFORM_KEY', 'emall'),

    // OPTIONAL override. The currency of the money published is the storefront's (SHOP_CURRENCY,
    // config/shop.php), or rather the currency each order was placed in. Leave this blank. Set it only
    // to PIN the published currency: money in any other currency is then left out of the snapshot (with
    // a logged warning) rather than relabelled or converted, and so is money when the value is not a
    // three-uppercase-letter ISO 4217 code.
    'currency' => env('ANALYTICS_CURRENCY') ?: null,

    // Also publish aggregate engagement counts (product_views_7d, add_to_cart_7d, wishlist_adds_7d).
    // Off by default: turn it on only after Dot.Analytics accepts those keys for the `emall` platform, or it
    // may refuse the whole snapshot. Counts of events on the company's own stores; nothing row-level.
    'commerce_metrics' => (bool) env('ANALYTICS_COMMERCE_METRICS', false),

    // Seconds, for connecting and for the whole request.
    'timeout' => 5,

    // After `threshold` consecutive failed calls, stop calling for `pause_seconds`.
    'circuit' => [
        'threshold' => 5,
        'pause_seconds' => 60,
    ],
];
