<?php

/*
|--------------------------------------------------------------------------
| Paystack
|--------------------------------------------------------------------------
|
| Dot.Emall takes the buyer's payment into the PLATFORM's Paystack account.
| Seller payouts, split payments, marketplace commission and KYC are not
| built (see wiki.md). Leave the keys blank and checkout behaves as it did
| before Paystack: orders are placed pending and unpaid.
|
| TEST MODE GUARD: a key starting `sk_live_` is refused (checkout is stopped
| and nothing is ever sent to Paystack with it) unless PAYSTACK_ALLOW_LIVE is
| also true. Live money must be switched on deliberately, in two places.
|
*/

return [

    'secret_key' => env('PAYSTACK_SECRET_KEY', ''),

    'public_key' => env('PAYSTACK_PUBLIC_KEY', ''),

    // Must be https. Only change it for a Paystack-compatible sandbox you run yourself.
    'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),

    'allow_live' => (bool) env('PAYSTACK_ALLOW_LIVE', false),

    // Paystack charges in the STOREFRONT currency (SHOP_CURRENCY, config/shop.php):
    // one source of truth, so the shop and the charge cannot disagree. Leave this
    // unset. It exists only to be checked: if it is set and differs from
    // SHOP_CURRENCY, checkout is refused (see PaystackConfig::problem()).
    // Amounts are sent in the subunit (cents for ZAR).
    'currency' => env('PAYSTACK_CURRENCY') ?: null,

    // An order that requires payment and is still unpaid after this many
    // minutes is cancelled by `orders:expire-unpaid` (stock and promo use go
    // back through OrderLifecycle). Paid orders are never touched.
    'unpaid_window_minutes' => (int) env('PAYSTACK_UNPAID_WINDOW_MINUTES', 60),

    // How long "Pay now" reuses an open Paystack attempt before starting a new one.
    'attempt_ttl_minutes' => (int) env('PAYSTACK_ATTEMPT_TTL_MINUTES', 30),

];
