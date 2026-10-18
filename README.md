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

## Ecosystem

**Dot.Emall** is one of the platforms in the Dot ecosystem, connected via shared PostgreSQL and
Sanctum SSO. Visit [Dot.Brain](https://github.com/sakhilebhayi/Dot.Brain) for the ecosystem-wide
knowledge repo, and [`wiki.md`](wiki.md) for this platform's own source of truth.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
