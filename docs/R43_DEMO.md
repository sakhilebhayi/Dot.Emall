# R43 demo marketplace

A believable, fictional South African marketplace for design, audit and demo work. It is built through the real
models and services (checkout, payment confirmation, order lifecycle, refunds, reviews), so totals, snapshots, stock,
refunds and ratings are exactly what the live code produces. It never runs in production.

## Build it

```
php artisan migrate
php artisan storage:link          # product photos live on the public disk
php artisan emall:demo-seed       # first run builds everything (about 5 seconds); a second run changes nothing
php artisan emall:demo-seed --fresh   # removes only what the seeder made, then rebuilds
```

The command refuses to run when `APP_ENV=production` (exit code 1, nothing written). The seeder class
(`Database\Seeders\EmallDemoSeeder`) refuses too, so `db:seed --class=EmallDemoSeeder` is equally safe.

Everything the seeder owns uses an `@emall-demo.test` address, which is how `--fresh` finds its own rows again (behaviour rows
are found through the keyed hashes of the demo people and of eight fixed visitor tokens). Other users, stores and orders are never
touched; the shared category tree and its spec definitions are not the seeder's and stay. No network call is made: Paystack replies are faked while seeding.

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

Built through the real services with the clock moved back while each one runs (so every timestamp is genuinely in the past
and the order history, return window, ledger release and reference prices behave exactly as they will in life).

**Catalogue.** The shared category tree (`emall:seed-categories`, about 130 categories with their spec definitions) is seeded
inside the command. 4 stores with logos, a profile (province, city, claimed company and VAT numbers, return and shipping
policies) and a trust ladder: two sellers signed in through InfoDot and linked a company, one signed in only, one neither.
120 products on 36 categories, with generated neutral pictures (one to three each, no text, no brand marks), brands (real
brand names as labels for electronics, the store's own label for homeware), structured attributes checked against the category
definitions (laptops with RAM, storage, processor, screen size; phones; televisions; monitors; fridges; furniture) and variants
with their own price and stock: clothing in sizes and colours, shoes by size, phones by storage and colour, a sofa, duvet sets,
a helmet. Listing states: approved, pending approval (3), rejected with a reason (1), switched off (1), sold out (several),
low stock.

**Shipping and addresses.** Every store sells a flat-rate delivery, a free-over-a-threshold delivery and collection, with
province rules (price by province, a faster method for two provinces). Buyers have saved addresses (two have two).

**Orders (32).** Awaiting payment, payment failed, paid and waiting, packing, shipped with a shipment, carrier, tracking number
and the seller's own tracking events, delivered, ready for collection, collected, cancelled before and after payment, partly
refunded, a four-store order whose lines sit in four statuses, a pre-Paystack order, and a guest order (see below). Three
orders put the same laptop and mouse together and three put a tent and a sleeping bag together, so "bought together" has
real orders behind it. A 5% platform fee is applied while seeding so the seller ledger shows a fee (the fee is an assumption of
the demo; the application default is 0).

**Returns, one in every state**, each inside the 7 day window: requested, approved, declined with a reason, received and refunded
(the line is Refunded, the ledger reversed, delivery kept), declined then escalated (waiting in the operator queue), and escalated
then closed by the operator.

**Ledger.** Entries held (waiting out the 7 days, or an open return) and released (delivered long enough ago), reversals.

**Reviews.** 21 verified reviews written through `ReviewService` from delivered lines, 3 legacy unverified rows (inserted directly
because the application refuses them), two store replies, helpful votes, one report in the operator queue.

**Price history.** Genuine earlier prices: a laptop, a television, a notebook computer and others dropped after 60 to 90 days of
history, so their "was" price (lowest in the last 30 days) shows; a keyboard rose 45 days ago and a mouse was cheaper 70 days ago,
so nothing shows for them (the rules in `ReferencePrice`). Seller-typed compare-at prices are never used.

**Discovery.** Wishlists (14 items, some with a chosen variant), 3 saved searches, 5 alerts (back in stock, price drop; one price
drop has fired and sits in a notification), what people viewed and when (signed in and 8 anonymous visitors, so "viewed together"
has five or more people behind it), what people searched for (popular searches need 3 different people; two queries found
nothing for the operator's zero-result report), 6 questions (4 answered), 4 collections (2 dynamic, 1 manual, 1 draft), a cart
left behind, a preference (Naledi opted in to alert e-mail), recommendation links and the search index, built at the end.

The command ends by running `emall:search-reindex` and `emall:recommendations-rebuild`, releasing the ledger entries that are
due, and printing the **guest order link**.

## The cast and where to look

| Who | Look at |
|---|---|
| Thandi | `/orders/20` television delivered (reference price on the product); `/cart` a cart left behind; `/orders/26` a return requested; `/orders/25` waiting for collection |
| Pieter | `/orders/27` return approved; `/orders/15` the laptop and mouse; wishlist, a price-drop alert that has fired |
| Naledi | `/orders/22` a phone in transit with tracking events; `/orders/28` return declined; `/orders/31` return closed by the operator |
| Sipho | `/orders/6` shipped with tracking; `/orders/30` return escalated; `/orders/24` collected; `/orders/10` awaiting payment |
| Ayesha | `/orders/29` return received and refunded; `/orders/23` clothing with sizes and colours |
| Lerato, Johan, Zanele, Kabelo | `/dashboard`, `/sell/orders` (returns waiting for them, a held and a released ledger) |
| Marcus (operator) | `/operator/products`, `/operator/payments`; the escalated return and the reported review wait in the queues the feature agents build |
| Nomsa | nothing: the first-login state |

Order numbers are stable on an empty database (the first run gives order 1 to 32 in the order above); use
`/dev/login/<key>@emall-demo.test?to=/path` to sign in as anyone.

**Guest order.** `guest.shopper@emall-demo.test` has a paid order with one parcel shipped. The command prints
`Guest order link (no sign-in needed): <APP_URL>/guest/orders/<id>/<token>` every time. The link is stable for a given
`APP_KEY` and order id, so after `--fresh` on an empty database it is the same. Today it answers a small JSON summary until the
checkout agent registers `guest.orders.show`.

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
every order, payment and return state, the category tree, brands, attributes, variants, shipping, tracking, the ledger (which
reconciles), verified reviews, the reference-price rules, the guest link, discovery data and the search index; a second run and
`--fresh` leave identical counts; purge removes everything the seeder made and nothing else; the dev login works by email and by
id, rejects off-site `to` values and signs out; the dev routes are absent in production.

Known limits: the seeded awaiting-payment order carries a placeholder Paystack checkout address, so "Pay now" on it opens a Paystack 404 page. Place a new order with a test key to reach a real checkout.
