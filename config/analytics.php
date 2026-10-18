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

    // ISO 4217 code of the money in this marketplace. Dot.Emall has no currency setting of its own
    // (prices are plain decimals and no payment provider exists), so nothing is assumed: while this
    // is blank or not a three-letter code, the revenue key is left out of every snapshot.
    'currency' => env('ANALYTICS_CURRENCY'),

    // Seconds, for connecting and for the whole request.
    'timeout' => 5,

    // After `threshold` consecutive failed calls, stop calling for `pause_seconds`.
    'circuit' => [
        'threshold' => 5,
        'pause_seconds' => 60,
    ],
];
