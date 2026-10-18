<?php

/*
|--------------------------------------------------------------------------
| Storefront currency
|--------------------------------------------------------------------------
|
| The ONE currency Dot.Emall trades in. Prices are plain decimals in this
| currency; every amount a page, notification or snapshot prints goes through
| App\Support\Shop (never a hard-coded symbol), Paystack charges in it, and
| Dot.Analytics is told it.
|
| An order stores the currency it was placed in (orders.currency), so changing
| this later never relabels history: old orders keep their own currency, new
| orders and the catalogue use the new one. Nothing is converted. Prices are
| NOT re-priced either, so change it on a fresh marketplace, or re-price the
| listings by hand in the same breath.
|
| ISO 4217 code: exactly three uppercase letters. Anything else stops the app
| from booting with a message naming SHOP_CURRENCY (App\Providers\AppServiceProvider).
| A blank value counts as unset and means ZAR, like every other Dot platform.
|
*/

return [

    'currency' => env('SHOP_CURRENCY') ?: 'ZAR',

];
