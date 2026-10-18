<div align="center">

<img src="public/images/logo.png" alt="Dot.Emall" width="200" />

<br /><br />

**Open a store, list products, and sell to every buyer in the Dot Ecosystem.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [Dot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `emall.infodot.app`

</div>

---

## What is Dot.Emall?

Dot.Emall is the marketplace platform in the Dot ecosystem: a multi-vendor storefront application
where sellers open a store, list products, and fulfil orders, while buyers browse and buy across
every store in the marketplace.

**Status:** built and running as a Laravel application. See [`wiki.md`](wiki.md) for the full,
kept-honest breakdown of what is implemented and what is still roadmap.

> **Payments: Paystack, in TEST MODE until you deliberately say otherwise.** With a Paystack test key
> configured, checkout places the order and sends the buyer to Paystack to pay the *platform's*
> Paystack account; sellers can fulfil an order only once Paystack has confirmed the money. With no key
> configured, checkout works exactly as it did before: the order is placed **pending and unpaid** and
> says so. **Seller payouts, split payments, commissions and KYC are NOT built**: nobody is paid out, and
> every seller screen says the amounts shown are *earned, not paid out*. See
> [Paystack payments](#paystack-payments).

## What works today

**Buyers**
- Public storefront: browse, search and filter products (`/products`) and open a product page with
  its photo gallery, price, stock and reviews, without signing in. Buying needs an account.
- Cart (`/cart`) with stock checks on add and on quantity change, then checkout. Checkout creates a
  real `Order` and `OrderItem` rows inside one transaction (stock locked and decremented), collects a
  delivery name, address and optional phone, accepts a store promo code, and is idempotent: a
  single-use token means a double submit or a retried request can never place two orders. Checkout
  is rate-limited (10 a minute per user) and wrong promo guesses are budgeted per user and per IP.
- Paying (when Paystack is configured): checkout redirects to Paystack; a **Pay now** button on the
  order page retries or resumes payment; an order not paid within 60 minutes is cancelled, restocked and
  its promo use returned. Cancelling a paid order before it ships queues a refund.
- Order history (`/orders`). A buyer can cancel an item before it ships; it goes back into stock and
  the order is re-priced without it.
- Reviews from verified buyers only: one review per product, after an order containing it has been
  delivered.

**Sellers** (one store per user)
- Open a store, edit it, close and reopen it (`/sell`, `/sell/store/edit`).
- Create, edit, deactivate and delete listings (`/sell/products`), with up to 6 photos each (the
  first is the cover; photos are content-checked, resized and re-encoded to strip location data).
  New listings are reviewed by a platform operator before they appear.
- Orders to fulfil (`/sell/orders`): a seller sees only their own items of an order, their own net
  value, and the buyer's name, address and phone only while one of their items is still to ship.
  Items move forward pending, processing, shipped, delivered, or are cancelled before shipping. An
  order that is awaiting payment shows **Awaiting payment**, cannot be moved forward and does not
  release the buyer's address.
- Store promo codes (`/sell/promo-codes`): percent or fixed, optional cap and expiry, unique per
  store, matched case-insensitively. The discount applies only to that store's items.
- A seller desk (`/dashboard`) with figures computed from the seller's own order lines. Revenue is
  *earned, not paid out*: only delivered lines of paid orders count, and payout is not available yet.

**Everyone**
- In-app notifications (database channel): sellers are told once per order when they have items to
  fulfil; buyers are told when an item changes status.
- Order history never breaks: each order line snapshots the product name, store and seller, so
  editing, archiving or deleting a listing does not change what a past order says.
- Dark/light toggle, ecosystem SSO from the Dot hub, operator review screen for new listings.

**Operators** also get a payments desk (`/operator/payments`): payments that need review, refunds owed
to buyers, unmatched Paystack events, and the gateway's state.

**Not built:** seller payouts, split payments or subaccounts, marketplace commission, KYC, saved cards,
returns (cancelling a paid order before shipping is refunded; sending goods back is not modelled),
multiple stores per user (a deliberate one-store rule), timed or automatic discounts,
search integration (search is a plain `LIKE`), and a Dot.Brain Knowledge Pack publisher beyond the
single `incident_report` pack described in `wiki.md`.

## Domain Models

- **Store** (one per owner) / **StoreCategory** (self-referential tree)
- **Product** (soft-deleted, moderation `status`, stored average rating) / **ProductImage** (ordered, cover first)
- **Order** (buyer, shipping contact, promo snapshot, idempotency key) / **OrderItem** (snapshot of what was bought, per-line status and promo share)
- **PromoCode** (per store) / **Review** (verified, one per product per user) / **CartItem**
- **Payment** (one Paystack attempt: reference, amount in cents, status) / **PaymentEvent** (audit trail
  of what Paystack said and what we did) / **Refund** (money owed back to a buyer)
- Services: `CheckoutService`, `OrderLifecycle` (cancellation and status moves), `OrderPricing`
  (totals and discount shares, exact to the cent), `OrderAccess` (who sees what of an order),
  `SellerDashboard`, `ReviewService`, `ProductImages`, `PaymentService`, `RefundService`,
  `PaystackWebhookHandler` and `Paystack\PaystackClient` / `PaystackConfig` (the only code that talks to
  Paystack, and the test-mode guard).

## Responsive app shell

Signed-in pages render inside `resources/views/layouts/app.blade.php`. From 860px up it is the fixed 260px sidebar and top bar, as before. Below 860px the sidebar becomes an off-canvas drawer that the **Menu** button in the top bar opens (`#app-sidebar`, `[data-shell-open]`, `[data-shell-close]`). `layouts/partials/shell-script.blade.php` moves focus into the drawer, closes it on Escape or a backdrop click, locks page scroll, makes the page behind it inert and returns focus to the button. It is plain JavaScript, no Alpine or build step.

- Wide tables go in a `<div class="table-scroll" role="region" aria-label="..." tabindex="0">` so only the table scrolls sideways.
- Page markup is mostly inline styles, so the layout carries a few phone-only attribute-selector rules (page padding, grids collapse to one column under 640px, 44px controls, 16px field text so iOS does not zoom). New pages should prefer classes and `minmax()` grids over fixed widths.
- The Jetstream `navigation-menu` bar that sits under the fixed top bar is wrapped in `.shell-legacy-nav` and hidden on phones. Its team switcher and sign-out now live in the drawer footer. On the public storefront the product grid is two columns on phones (`.product-grid`).
- `tests/Feature/ResponsiveShellTest.php` asserts the markup hooks above. It checks the rendered HTML, not pixels, so layout still needs a look in a browser at 360, 375, 768 and 1280px.

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Language | PHP 8.4 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared `infodot` database across the ecosystem) |
| Realtime | Laravel Reverb (configured; not used by any commerce flow yet) |
| Auth | Laravel Jetstream + Sanctum, team-based accounts (Dot ecosystem SSO) |
| AI | Anthropic Claude (`ANTHROPIC_API_KEY`, `claude-sonnet-4-6`) — config only, not called by any marketplace logic yet |
| Payments | Paystack (customer payments into the platform account; test mode by default) |
| Queue | Database-backed queue |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Emall.git
cd Dot.Emall
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Back up the database before `php artisan migrate`** on an existing install: the currency
> migration (below) stamps every existing order with `SHOP_CURRENCY` and `down()` cannot restore a
> value you later correct by hand.

> **Ecosystem SSO:** Set `DB_*` env vars to the shared Dot ecosystem PostgreSQL instance and
> `APP_URL=https://emall.infodot.app`. Users authenticated elsewhere in the ecosystem gain access
> automatically via the Sanctum handoff at `/auth/ecosystem`.

## Storefront currency

One currency, set in `.env`:

| Variable | Default | Meaning |
|---|---|---|
| `SHOP_CURRENCY` | `ZAR` | ISO 4217 code, **three uppercase letters**. Anything else stops the app from starting, with a message naming `SHOP_CURRENCY`. A blank value counts as unset (`ZAR`) |

Every price, cart total, order total, promo amount, seller figure and notification is printed through
`App\Support\Shop` (`Shop::money($cents)`, `Shop::amount($decimal)`), in the same format as Dot.Auction:
the symbol straight against the number, a comma between thousands, two decimals. `ZAR` prints `R1,234.56`,
`USD` `$1,234.56`, `EUR` `€1,234.56`, `GBP` `£1,234.56`; any other code prints as a prefix
(`CHF 1,234.56`). Prices stay plain decimals in this currency; nothing is converted.

- **Orders remember their currency.** `orders.currency` is stamped at checkout, and order pages, refunds
  and the seller's figures use the order's own currency. Changing `SHOP_CURRENCY` later does not
  relabel history: old orders keep theirs, the catalogue and new orders use the new one. It does not
  re-price listings either, so change it on a fresh marketplace or re-price by hand at the same time.
- **Sellers never see mixed currencies.** The dashboard's "Delivered value" has one row per currency
  when a seller has orders in several; nothing is added across them.
- **A literal currency symbol can no longer be typed into a view or notification:** a test
  (`NoHardCodedCurrencyTest`) fails the build on one.
- **Migration:** `2026_10_21_100000_add_currency_to_orders_table` adds `orders.currency`
  (`string(3)`, not null) in one statement with the current `SHOP_CURRENCY` as the default, so every
  existing order is stamped with it. That is a best guess (the old storefront printed `$` and never
  recorded a currency); correct it by hand per order if your history was in something else. An invalid
  `SHOP_CURRENCY` stops the migration before it changes anything.

## Paystack payments

The buyer pays the **platform's own Paystack account** for an order. Nothing else is built on top of it
yet: no seller payouts, no split payments or subaccounts, no marketplace commission, no KYC, no card
on file. Funds sit in the platform's Paystack balance; moving money to sellers needs compliance and
finance decisions that have not been made. Sellers are never told or shown that they have been paid.

### Set up (test mode)

1. Create a Paystack account and copy its **test** keys (Dashboard, Settings, API Keys & Webhooks).
2. In `.env`:

   | Variable | Default | Meaning |
   |---|---|---|
   | `PAYSTACK_SECRET_KEY` | blank | `sk_test_...`. Blank = Paystack off: checkout places orders pending and unpaid, as before |
   | `PAYSTACK_PUBLIC_KEY` | blank | `pk_test_...` (not used by the server-side flow today; reserved) |
   | `PAYSTACK_BASE_URL` | `https://api.paystack.co` | Must be https |
   | `PAYSTACK_ALLOW_LIVE` | `false` | A `sk_live_` key is refused unless this is also `true` |
   | `PAYSTACK_CURRENCY` | unset | Do not set. Paystack charges in `SHOP_CURRENCY` (in its subunit, cents). If it is set and differs, card payments are refused with a message on the operator payments desk and nothing is sent to Paystack |
   | `PAYSTACK_UNPAID_WINDOW_MINUTES` | `60` | Unpaid orders older than this are cancelled |
   | `PAYSTACK_ATTEMPT_TTL_MINUTES` | `30` | How long Pay now reuses an open Paystack attempt |

3. In the Paystack dashboard set the **Webhook URL** to `https://<your-domain>/webhooks/paystack`. The
   callback URL is sent per transaction (`/orders/{order}/payment/callback`), so nothing is configured
   for it. Test and live modes have separate webhook settings in Paystack.
4. Run the scheduler (`* * * * * php artisan schedule:run`): it runs `orders:expire-unpaid` every
   10 minutes.
5. Pay with one of Paystack's test cards. No real money moves in test mode.

### Test-mode guard

- A key that does not start `sk_test_` or `sk_live_` is refused.
- A `sk_live_` key is refused **unless `PAYSTACK_ALLOW_LIVE=true` too**. A refused key is never sent
  anywhere: checkout is stopped before an order exists (the buyer sees "card payment is not available
  right now"), the webhook answers 401, and `/operator/payments` says the key was refused.
- Pages that take payment show a **TEST MODE** badge whenever a test key is configured.
- Do not flip live on without reading "Not built" above first.

### The flows

- **Checkout.** The existing locked, idempotent checkout creates the order (stock, promo and totals
  unchanged), flagged `requires_payment`. The buyer is redirected to Paystack's `authorization_url`
  (`POST /transaction/initialize`, amount in cents, a reference like `EMALL-<order id>-<random>`).
  If Paystack cannot be reached the order is saved, unpaid, and the order page shows **Pay now**.
- **Callback.** `GET /orders/{order}/payment/callback` (the order's buyer only; everyone else gets a
  404). The URL's query string is never believed: the server calls `GET /transaction/verify/:reference`
  and the order is marked paid only if the status is `success` **and** the amount, currency and
  reference match the attempt.
- **Webhook.** `POST /webhooks/paystack` (no session, no CSRF, throttled). The `x-paystack-signature`
  header must equal the HMAC-SHA512 of the raw body keyed with the secret key (`hash_equals`), checked
  before the body is parsed; any failure is the same generic 401. `charge.success` marks the order paid
  exactly once however the callback and webhook race (a transaction with the order row locked, plus
  unique indexes on `payments.reference` and `(provider, provider_transaction_id)`). A charge with an
  unknown reference or a wrong amount or currency is recorded as an `unmatched` event and answered 200.
- **Fulfilment.** Sellers see **Awaiting payment** and cannot move the order forward, and are not given
  the buyer's address or a "new order" notification, until it is paid. Orders placed while Paystack was
  not configured (`requires_payment = false`) behave exactly as before and are never auto-cancelled.
- **Unpaid orders expire.** Past `PAYSTACK_UNPAID_WINDOW_MINUTES` an unpaid order is cancelled through
  `OrderLifecycle` (stock restored, promo use returned) and the buyer is notified. Open attempts are
  checked with Paystack first, and the cancel re-checks under the order lock, so a payment that lands
  first wins. A paid order is never cancelled this way.
- **Paid after cancelled.** If money arrives for an order that was cancelled (or a second payment for
  an order already paid) the order is **not** re-opened: the payment goes to *needs review* on
  `/operator/payments`.
- **Refunds.** Cancelling lines of a **paid** order before shipping records what the buyer is owed
  (`refund_pending`) and asks Paystack (`POST /refund`, partial amount). If Paystack accepts, the refund
  is `refund_submitted`; it is only `refunded` when Paystack reports `refund.processed`. If the call
  fails the cancellation still stands and the operator desk shows **Refund required**, with Retry or
  "do it in the Paystack dashboard, then mark it done".
- **Seller revenue** keeps its definition (delivered, non-cancelled lines) but counts only orders a
  seller could fulfil, i.e. paid ones (and pre-Paystack orders). It is labelled earned, not paid out.

### Known limits

- A payment is for the **order's** currency. If `SHOP_CURRENCY` is changed after an order was placed and
  unpaid, Pay now refuses it ("placed in ZAR, but card payments now charge in EUR") and nothing is charged;
  the buyer must be helped by an operator.
- Orders placed before Paystack was configured cannot be paid afterwards (they never required it).
- Paystack never being called from the automated tests is deliberate (`Http::fake`); the first real
  test-mode run against Paystack is still to be done by hand.

### Running Tests

```bash
php artisan test
```

Feature tests use an in-memory SQLite connection (see `phpunit.xml`) and Laravel's
`RefreshDatabase` trait — no shared Postgres instance required to run them. The migration tests in
`tests/Feature/Migrations` build the schema as it was before a migration, insert legacy rows, run
the real migrations and check what happened to that data.

Listing photos are stored on the `public` disk: run `php artisan storage:link` once per environment
and make sure the PHP `gd` extension is installed (it is what strips metadata from uploads).

## Daily metrics to Dot.Analytics

Once a day this app posts aggregate numbers, server to server, to Dot.Analytics
(`POST {ANALYTICS_INGEST_URL}`, the full URL ending `/api/ingest/snapshots`). The request is signed
with HMAC-SHA256 over `"{timestamp}.{raw body}"` using `ANALYTICS_INGEST_SECRET`, headers
`X-InfoDot-Timestamp` (unix seconds) and `X-InfoDot-Signature` (`sha256=<hex>`), computed when the
request is sent, so a retry carries a fresh timestamp. The body is

```json
{"platform": "emall", "company_uuid": "<uuid>", "taken_at": "2026-11-05T00:00:00Z", "period": "day", "metrics": {"orders_7d": 0, "revenue_7d_cents": 0, "low_stock_products": 0, "currency": "ZAR"}}
```

`emall:publish-metrics` collects and queues one `SendAnalyticsSnapshot` job per company. It takes
`--company=<uuid>` (one company only) and `--dry-run` (collect and print, queue and send nothing).

**What is sent: these keys and nothing else** (Dot.Analytics' metrics contract, `config/metrics.php`).
The definitions are the seller dashboard's own (`App\Services\SellerDashboard`), and the unit is a
seller's own order **line**, matched on the `seller_id` snapshot taken at checkout:

| Key | Meaning |
|---|---|
| `orders_7d` | distinct orders placed in the last 7 whole UTC days that hold at least one non-cancelled line of the company's stores |
| `revenue_7d_cents` | the company's **delivered** lines of those orders, each at its line total less its share of the promo discount, in integer cents. Another store's lines in a mixed order never count. It is the value of delivered lines, not money received (payment is not implemented). There is no delivery timestamp, so a line counts in the window its order was **placed** in, and an order placed on day 6 that is delivered on day 8 is missed by that day's snapshot |
| `low_stock_products` | active products in the company's stores with fewer than 5 in stock right now (`SellerDashboard::LOW_STOCK_BELOW`); archived products are not counted |
| `currency` | the ISO 4217 code of `revenue_7d_cents`: the currency of the orders (`orders.currency`; `SHOP_CURRENCY` when they were placed) |

**Currency: `revenue_7d_cents` and `currency` are sent whenever the company's delivered lines are in one
currency.** That is the normal case: the code is the orders' own currency (`SHOP_CURRENCY`, `ZAR` by
default), or today's `SHOP_CURRENCY` when there is nothing delivered yet (a true zero). One snapshot carries
one `currency`, so if a company's delivered lines in the window span several currencies (the setting was
changed mid-window) both money keys are **left out** and a warning is logged, as Dot.Billing does;
`orders_7d` and `low_stock_products` are still sent. Nothing is ever converted or added across currencies.
`ANALYTICS_CURRENCY` is an optional override that only *pins* the published currency: money in any other
currency, or an override that is not three uppercase letters, is left out the same way (never relabelled).

Aggregates only: no names, emails, store or product names, SKUs, addresses, phone numbers, promo
codes and no row ever appears in the body. A company with no activity sends true zeros (zero is a real
count).

**Which company:** stores belong to their owner, a user, and Dot.Analytics wants a company, so the
company is the InfoDot team (`infodot_team_uuid`) the store owner currently belongs to: their current
team's uuid, or their personal team's if the current team has none (the rule the Dot.Notify client
uses in the other Dot apps). The stores of one company are added together. A seller with no
`infodot_team_uuid` (not provisioned from InfoDot) is in no company and is **never published**; users
who own no store are not companies. A seller who switches teams is reported under the new team from
then on; history already sent stays with the old one. There is no platform-wide snapshot: every number
belongs to a company's own stores, and no `ANALYTICS_COMPANY_UUID` exists here.

**Windows are UTC.** `taken_at` is the start of the UTC day the command runs on, and the "7d"
numbers cover the seven whole UTC days before it, so a re-run on the same day describes the same
snapshot and Dot.Analytics keeps the first one (replays are idempotent per company, platform,
`taken_at` and period). `low_stock_products` is read at collection time, shortly after 00:15 UTC.

**What it takes for anything to be sent:** nothing is collected, queued or sent until
`ANALYTICS_INGEST_URL` **and** `ANALYTICS_INGEST_SECRET` are both set. The secret is this app's
`ANALYTICS_INGEST_SECRET_EMALL` value in Dot.Analytics' `.env` (same value; `ANALYTICS_PLATFORM_KEY`
is `emall`), and each company must be connected there. The **scheduler cron is required**
(`* * * * * php artisan schedule:run`; the command runs daily at 00:15 UTC) and so is a **queue
worker** (`php artisan queue:work`); with `QUEUE_CONNECTION=sync` the send happens inside the command.

**Safety:** a Dot.Analytics outage or misconfiguration cannot affect this app. The send is a queued
job, never part of a request; it has a 5 second HTTP timeout, 3 tries with 30 s and 120 s backoff,
never throws, logs without secrets or numbers, and a cache-based circuit breaker pauses calls for 60
seconds after 5 consecutive failures. A refusal (401, 404, 422) is not retried. A collection or
queueing error is logged and only ends that run of the command.

## Ecosystem

**Dot.Emall** is one of the platforms in the Dot ecosystem, connected via shared PostgreSQL and
Sanctum SSO. Visit [Dot.Brain](https://github.com/sakhilebhayi/Dot.Brain) for the ecosystem-wide
knowledge repo, and [`wiki.md`](wiki.md) for this platform's own source of truth.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
