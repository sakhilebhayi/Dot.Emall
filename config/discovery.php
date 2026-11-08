<?php

/*
|--------------------------------------------------------------------------
| Discovery and engagement (R-43 data agent B)
|--------------------------------------------------------------------------
| Search, recommendations, wishlists, alerts, Q&A, review extras, collections, the
| homepage data and the shopping assistant. Every threshold below exists so that a
| number is only ever shown when enough real data stands behind it.
*/

return [
    'search' => [
        // 'auto' picks PostgreSQL full-text on a PostgreSQL connection and the portable engine elsewhere;
        // 'portable' forces the portable engine (for a host whose full-text is unusable).
        'engine' => env('DISCOVERY_SEARCH_ENGINE', 'auto'),
        'per_page' => 24,
        'max_page' => 200,
        // Seconds to remember facet counts for an identical request. Off outside production so tests and
        // local demos always see their own writes.
        'facet_cache_seconds' => (int) env('DISCOVERY_FACET_CACHE_SECONDS', env('APP_ENV') === 'production' ? 60 : 0),
        // Seconds to remember which database features exist (pg_trgm, the tsvector column).
        'capability_cache_seconds' => 3600,
        // The ranking bonus for ratings only counts once a product has at least this many reviews.
        'rating_min_reviews' => 3,
        // "Best selling" ranks only products with at least this many units sold in counted orders.
        'best_seller_min_units' => 3,
        'max_facet_values' => 20,
        // /search is public and each filter adds a join or a chip: a request is read with at most this many attribute filters, and this many values per filter.
        'max_attribute_filters' => 6,
        'max_filter_values' => 12,
        'max_query_length' => 100,
    ],

    'suggest' => [
        'products' => 6,
        'categories' => 3,
        'brands' => 3,
        'recent' => 5,
        'popular' => 5,
        // A search is "popular" only when this many different people ran it in the window and it found something.
        'popular_min_people' => 3,
        'popular_window_days' => 30,
        // Seconds to remember the popular-searches aggregate. Production only, like the facet cache.
        'popular_cache_seconds' => (int) env('DISCOVERY_POPULAR_CACHE_SECONDS', env('APP_ENV') === 'production' ? 300 : 0),
    ],

    'retention' => [
        'search_queries_days' => 90,
        'product_views_days' => 90,
        'commerce_events_days' => 400,
    ],

    'views' => [
        'keep_per_person' => 20,
    ],

    'recommendations' => [
        'bought_together_min' => 3,
        'viewed_together_min' => 5,
        'window_days' => 180,
        'max_lines_per_order' => 30,
        'per_product' => 12,
        'cache_seconds' => 3600,
    ],

    'homepage' => [
        'trending_window_days' => 7,
        // Views plus order units in the window needed before a product may be called trending.
        'trending_min_events' => 5,
        'popular_category_min_events' => 5,
        'new_arrival_days' => 30,
        'featured_store_min_orders' => 3,
        'featured_store_window_days' => 90,
        'section_size' => 8,
        'cache_seconds' => 300,
    ],

    'wishlist' => [
        'max_items' => 200,
        'max_saved_searches' => 20,
        'max_alerts' => 100,
    ],

    'qa' => [
        'min_question_length' => 10,
        'max_question_length' => 500,
        'max_answer_length' => 1500,
    ],

    'reviews' => [
        'edit_window_days' => 30,
        'max_photos' => 4,
        'max_photo_kilobytes' => 4096,
        'max_reviews_per_day' => 3,
        'duplicate_similarity' => 0.9,
        'min_body_length_for_duplicate_check' => 20,
        // A seller rating is shown only from this many real reviews.
        'store_rating_min_reviews' => 5,
    ],

    'assistant' => [
        'max_items' => 5,
        'max_text_length' => 400,
        'model' => env('DISCOVERY_ASSISTANT_MODEL', env('ANTHROPIC_MODEL', 'claude-sonnet-4-6')),
        'timeout_seconds' => 12,
    ],

    // Unit words in a request mapped to the attribute keys that may hold them.
    'units' => [
        'inch' => ['screen_size', 'size', 'screen'],
        'gb' => ['storage', 'memory', 'ram', 'capacity'],
        'tb' => ['storage', 'capacity'],
        'kg' => ['weight', 'capacity'],
        'litre' => ['capacity', 'volume'],
        'ml' => ['volume', 'capacity'],
        'w' => ['power', 'wattage'],
        'mah' => ['battery', 'battery_capacity'],
        'hz' => ['refresh_rate'],
        'cm' => ['size', 'length', 'width'],
        'mm' => ['size', 'length', 'width'],
    ],
];
