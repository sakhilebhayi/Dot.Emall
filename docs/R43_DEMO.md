# R43 demo marketplace

A believable, fictional South African marketplace for design, audit and demo work. It is built through the real
models and services (checkout, payment confirmation, order lifecycle, refunds, reviews), so totals, snapshots, stock,
refunds and ratings are exactly what the live code produces. It never runs in production.

## Build it

```
php artisan migrate
php artisan storage:link          # product photos live on the public disk
php artisan emall:demo-seed       # first run builds everything; a second run changes nothing
php artisan emall:demo-seed --fresh   # removes only what the seeder made, then rebuilds
```

The command refuses to run when `APP_ENV=production` (exit code 1, nothing written). The seeder class
(`Database\Seeders\EmallDemoSeeder`) refuses too, so `db:seed --class=EmallDemoSeeder` is equally safe.

Everything the seeder owns uses an `@emall-demo.test` address, which is how `--fresh` finds its own rows again.
Other users, stores and orders are never touched. No network call is made: Paystack replies are faked while seeding.

For the SQLite audit set-up used by the R43 audit:

```
export DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/audit.sqlite APP_URL=http://127.0.0.1:9001 \
       QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log SESSION_DRIVER=file CACHE_STORE=file
touch database/audit.sqlite && php artisan migrate --force && php artisan storage:link
npm run build && php artisan emall:demo-seed --fresh
php artisan serve --port=9001
```

## Accounts

Password for every account: `password`. All are email-verified and have a personal team.

| Key | Name | Role | Notes |
|---|---|---|---|
| thandi | Thandi Mokoena | buyer | Johannesburg. Multi-seller order, failed payment, delivered orders |
| pieter | Pieter van der Merwe | buyer | Cape Town. Promo orders, a pre-Paystack "pending and unpaid" order |
| naledi | Naledi Dlamini | buyer | Durban. Partially cancelled and partially refunded order, cancelled unpaid order |
| sipho | Sipho Khumalo | buyer | Pretoria. Order awaiting payment, shipped order |
| ayesha | Ayesha Patel | buyer | Durban. Fully cancelled and refunded order, a just-placed order |
| lerato | Lerato Mahlangu | seller | Karoo Home & Hearth (homeware) |
| johan | Johan Botha | seller | Veld & Vine Outdoors (camping) |
| zanele | Zanele Nkosi | seller | Ubuntu Beauty Co. (beauty and pantry) |
| kabelo | Kabelo Sithole | seller | Joburg Tech Hub (electronics and desk gear) |
| operator | Marcus Williams | platform operator | Listing review and the payments desk |
| nomsa | Nomsa Zulu | brand-new user | No orders, no store: first-login state |

Emails are `<key>@emall-demo.test`, for example `thandi@emall-demo.test`.

## What it contains

- 11 categories (a parent and child tree), 4 active stores with generated logos, 60 products with generated product
  images (one to three photos each), descriptions, prices, compare-at prices, SKUs and stock.
- Listing states: approved, pending approval (4), rejected with a reason (1), deactivated by the seller (1),
  out of stock (2), low stock (a handful).
- 8 promo codes across the four stores: percentage, fixed, expired, fully redeemed, inactive.
- 14 orders covering every state the storefront can show: awaiting payment, payment failed, paid and pending,
  processing, shipped, delivered, cancelled (unpaid), cancelled and refunded, partially cancelled and partially
  refunded, a four-store order whose lines sit in four different statuses, and a legacy order placed before card
  payments existed.
- 15 reviews: 12 verified (the buyer has a delivered line) and 3 unverified (inserted directly, because the
  application itself refuses them; they show how the storefront treats such rows).
- Notifications for buyers and sellers, a mix of read and unread, back-dated to their orders.

The generated images are plain studio-style renders (a soft backdrop, a floor shadow, one simple object). They carry
no text and no "demo" label.

Paystack is deliberately left unconfigured in the audit set-up, so a new checkout produces a "pending and unpaid"
order and the "Pay now" button on an awaiting-payment order has nothing to talk to. The seeded orders carry
Paystack payment rows (success, failed, pending) so the order pages render every payment state.

## Local-only dev sign-in

Registered in `routes/web.php` inside a `// --- dev ---` block, only when `app()->environment('local', 'testing')`.
In any other environment the routes do not exist at all (they are absent, not guarded; a test proves it).

```
GET /dev/login/{id|email}[?to=/orders]     signs in as that user; `to` must be a path on this site
GET /dev/logout                            signs out and returns to /
```

Example: `http://127.0.0.1:9001/dev/login/lerato@emall-demo.test?to=/dashboard`.

## Tests

`tests/Feature/Demo/EmallDemoSeedTest.php` proves: the command and the seeder refuse in production; the build covers
every order and payment state, including a multi-seller order; a second run and `--fresh` leave identical counts;
`--fresh` does not touch other people's data; the dev login works by email and by id, rejects off-site `to` values
and signs out; the dev routes are absent in production.

Known limit: the seeded awaiting-payment order carries a placeholder Paystack checkout address, so "Pay now" on it opens a Paystack 404 page. Place a new order with a test key to reach a real checkout.
