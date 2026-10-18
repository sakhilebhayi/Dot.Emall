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

> **Payment is NOT implemented.** The payment provider has not been chosen, so nothing in the
> codebase takes, records or implies a payment. Checkout places an order that is **pending and
> unpaid**, and every screen that shows an order says so. The dashboard's "delivered value" is the
> value of delivered items, not money received.

## What works today

**Buyers**
- Public storefront: browse, search and filter products (`/products`) and open a product page with
  its photo gallery, price, stock and reviews, without signing in. Buying needs an account.
- Cart (`/cart`) with stock checks on add and on quantity change, then checkout. Checkout creates a
  real `Order` and `OrderItem` rows inside one transaction (stock locked and decremented), collects a
  delivery name, address and optional phone, accepts a store promo code, and is idempotent: a
  single-use token means a double submit or a retried request can never place two orders. Checkout
  is rate-limited (10 a minute per user) and wrong promo guesses are budgeted per user and per IP.
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
  Items move forward pending, processing, shipped, delivered, or are cancelled before shipping.
- Store promo codes (`/sell/promo-codes`): percent or fixed, optional cap and expiry, unique per
  store, matched case-insensitively. The discount applies only to that store's items.
- A seller desk (`/dashboard`) with figures computed from the seller's own order lines.

**Everyone**
- In-app notifications (database channel): sellers are told once per order when they have items to
  fulfil; buyers are told when an item changes status.
- Order history never breaks: each order line snapshots the product name, store and seller, so
  editing, archiving or deleting a listing does not change what a past order says.
- Dark/light toggle, ecosystem SSO from the Dot hub, operator review screen for new listings.

**Not built:** payments of any kind (provider undecided), vendor payouts or commissions, returns and
refunds, multiple stores per user (a deliberate one-store rule), timed or automatic discounts,
search integration (search is a plain `LIKE`), and a Dot.Brain Knowledge Pack publisher beyond the
single `incident_report` pack described in `wiki.md`.

## Domain Models

- **Store** (one per owner) / **StoreCategory** (self-referential tree)
- **Product** (soft-deleted, moderation `status`, stored average rating) / **ProductImage** (ordered, cover first)
- **Order** (buyer, shipping contact, promo snapshot, idempotency key) / **OrderItem** (snapshot of what was bought, per-line status and promo share)
- **PromoCode** (per store) / **Review** (verified, one per product per user) / **CartItem**
- Services: `CheckoutService`, `OrderLifecycle` (cancellation and status moves), `OrderPricing`
  (totals and discount shares, exact to the cent), `OrderAccess` (who sees what of an order),
  `SellerDashboard`, `ReviewService`, `ProductImages`.

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

> **Ecosystem SSO:** Set `DB_*` env vars to the shared Dot ecosystem PostgreSQL instance and
> `APP_URL=https://emall.infodot.app`. Users authenticated elsewhere in the ecosystem gain access
> automatically via the Sanctum handoff at `/auth/ecosystem`.

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
| `currency` | the ISO 4217 code of `revenue_7d_cents`, from `ANALYTICS_CURRENCY` |

**Currency: `revenue_7d_cents` and `currency` are only sent when `ANALYTICS_CURRENCY` is set.**
Dot.Emall has no currency setting (prices are plain decimals, the views print a `$`, and no payment
provider exists), so none is assumed. Leave `ANALYTICS_CURRENCY` blank and the snapshot carries
`orders_7d` and `low_stock_products` only; set it (for example `ZAR`) only once the owner has decided
which currency the marketplace trades in. Nothing is ever converted or mixed across currencies.

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
