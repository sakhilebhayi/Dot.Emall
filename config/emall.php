<?php

/*
|--------------------------------------------------------------------------
| Dot.Emall commerce settings (R-43)
|--------------------------------------------------------------------------
|
| Every business term the build had to decide lives here, with the decision
| number from docs/R43_DECISIONS.md. Nothing below invents a commercial term:
| commission defaults to 0, VAT only applies to stores that say they are
| registered, and the gateway gate only bites in production.
|
*/

return [

    'checkout' => [
        // D-1: in production, refuse checkout while Paystack is not usable, so an unpaid order can never be shipped.
        'requires_gateway' => filter_var(env('EMALL_CHECKOUT_REQUIRES_GATEWAY', env('APP_ENV') === 'production'), FILTER_VALIDATE_BOOL),

        // D-4: stock is held this long for an unpaid order (extended while a payment attempt is open, at most
        // `hold_extension_cap` times this window from the order's creation). Mirrors paystack.unpaid_window_minutes.
        'stock_hold_minutes' => (int) env('EMALL_STOCK_HOLD_MINUTES', 30),
        'hold_extension_cap' => 2,

        // D-4: unpaid (payment-required) orders one buyer or one guest e-mail may hold at once.
        'max_unpaid_orders' => (int) env('EMALL_MAX_UNPAID_ORDERS', 3),
    ],

    // D-2: platform commission on the item value of a seller group. Default 0: no business term is invented.
    'commission' => [
        'percent' => (float) env('EMALL_COMMISSION_PERCENT', 0),
        // A seller group's earnings are released this many days after delivery (D-5, D-6).
        'release_after_days' => (int) env('EMALL_LEDGER_RELEASE_DAYS', 7),
    ],

    // D-3: displayed prices include VAT. Only stores that declare themselves VAT registered show a VAT component.
    'vat' => [
        'rate' => (float) env('EMALL_VAT_RATE', 0.15),
    ],

    'shipping' => [
        // A store with no shipping method at all still sells: the buyer is told delivery is arranged with the seller
        // and pays nothing extra. Set true to make "no method configured" a reason a store cannot be bought from.
        'require_methods' => filter_var(env('EMALL_SHIPPING_REQUIRE_METHODS', false), FILTER_VALIDATE_BOOL),

        // Tracking links are built ONLY from these templates; anything else stays plain text. {n} is replaced by the
        // tracking number after it has been checked against `pattern`. Templates are the carriers' public lookups
        // and are an owner item to re-verify before launch.
        'carriers' => [
            'courier_guy' => ['name' => 'The Courier Guy', 'url' => 'https://portal.thecourierguy.co.za/track?ref={n}', 'pattern' => '/^[A-Za-z0-9\-]{5,30}$/'],
            'aramex' => ['name' => 'Aramex', 'url' => 'https://www.aramex.com/za/en/track/results?ShipmentNumber={n}', 'pattern' => '/^[0-9]{6,20}$/'],
            'dsv' => ['name' => 'DSV', 'url' => 'https://www.dsv.com/en/tools/tracking?query={n}', 'pattern' => '/^[A-Za-z0-9\-]{5,30}$/'],
            'pargo' => ['name' => 'Pargo', 'url' => 'https://pargo.co.za/track-parcel/?waybill={n}', 'pattern' => '/^[A-Za-z0-9\-]{5,30}$/'],
            'pudo' => ['name' => 'PUDO', 'url' => 'https://www.pudo.co.za/track-a-parcel/?tracking={n}', 'pattern' => '/^[A-Za-z0-9\-]{5,30}$/'],
            'fastway' => ['name' => 'Fastway (Aramex)', 'url' => 'https://www.aramex.com/za/en/track/results?ShipmentNumber={n}', 'pattern' => '/^[A-Za-z0-9\-]{5,30}$/'],
            'post_office' => ['name' => 'South African Post Office', 'url' => 'https://tracking.postoffice.co.za/?id={n}', 'pattern' => '/^[A-Za-z0-9]{8,20}$/'],
        ],
    ],

    // D-9: guest order links.
    'guest' => [
        'token_days' => (int) env('EMALL_GUEST_TOKEN_DAYS', 180),
    ],

    // D-6: returns.
    'returns' => [
        'window_days' => (int) env('EMALL_RETURN_WINDOW_DAYS', 7),
        'seller_response_days' => (int) env('EMALL_RETURN_RESPONSE_DAYS', 10),
    ],

    // Seller onboarding: when true a new store starts as a draft and needs operator approval before it is public.
    // Default false keeps today's behaviour (a store is live on creation; individual listings are moderated).
    'seller' => [
        'requires_review' => filter_var(env('EMALL_SELLER_REQUIRES_REVIEW', false), FILTER_VALIDATE_BOOL),
        // Fewer orders than this and a trust metric says "not enough orders yet" instead of a percentage.
        'trust_min_sample' => 5,
    ],

    // Optional outbound events to Dot.Notify. Inert unless both are set.
    'notify' => [
        'url' => env('NOTIFY_INGEST_URL'),
        'secret' => env('NOTIFY_INGEST_SECRET'),
        'platform' => env('NOTIFY_PLATFORM_KEY', 'emall'),
    ],

    // Price history (D-10).
    'reference_price' => [
        'window_days' => 30,
    ],

    // Operator desk (R-43 W7). A review AID, never an automatic ban: a listing that matches a rule below is put in front of a person with the
    // rule and the evidence shown, and the person decides. Edit the lists here; matching is whole words, case-insensitive, on name and description.
    // Starting point from the research brief (docs/R43_RESEARCH.md section 8): no alcohol, tobacco, firearms, medicines or adult goods at launch,
    // and "replica" is the word counterfeit sellers use. Not legal advice: have an attorney confirm the list before widening what is sold.
    'prohibited_terms' => [
        'firearm', 'firearms', 'rifle', 'pistol', 'handgun', 'revolver', 'ammunition', 'ammo', 'taser', 'pepper spray', 'knuckle duster',
        'tobacco', 'cigarette', 'cigarettes', 'cigar', 'vape', 'vapes', 'e-cigarette', 'hookah',
        'whisky', 'whiskey', 'vodka', 'brandy', 'tequila', 'liquor', 'beer', 'cannabis', 'marijuana', 'dagga',
        'prescription', 'antibiotic', 'antibiotics', 'steroid', 'steroids', 'viagra', 'schedule 6',
        'sex toy', 'adult toy', 'porn',
        'replica', 'counterfeit', 'knockoff', 'knock-off',
    ],

    'flags' => [
        // Price rule: a listing priced below `low_ratio` x or above `high_ratio` x the median of its category, when the category has at least `min_peers` other listings.
        'low_ratio' => 0.25,
        'high_ratio' => 5.0,
        'min_peers' => 5,
        // New-store rule: a store younger than `new_store_days` with at least `high_value_count` listings priced at `high_value_cents` or more.
        'new_store_days' => 14,
        'high_value_cents' => 500000,
        'high_value_count' => 5,
        // Only listings that are pending or were listed/edited within this many days are scanned.
        'scan_days' => 60,
    ],
];
