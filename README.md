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
kept-honest breakdown of what's actually implemented vs. still roadmap.

## Core Features (implemented today)

- Real marketplace schema: stores, products, product images, orders, order items, reviews, and
  a cart
- Multi-vendor storefronts — each store belongs to an owning `User`
- Buyer-facing product marketplace: search and category filter (`/products`), product detail page
  with gallery, price, stock, and reviews (`/products/{product}`)
- Full cart -> checkout flow (`/cart`, `POST /cart`, `POST /cart/checkout`) — creates a real
  `Order` + `OrderItem` rows, decrements stock inside a transaction, and rejects checkout outright
  if any line item is out of stock
- Buyer order history (`/orders`, `/orders/{order}`), strictly scoped to the signed-in user via
  `OrderPolicy` — one customer can never view another customer's order
- Product reviews: buyers can post one review per product (`POST /products/{product}/reviews`)
  and delete their own; `ReviewPolicy` prevents deleting someone else's review
- Seller operations dashboard — total products, orders, revenue (delivered orders), pending
  orders, reviews received, average rating, and a low-stock alert widget across the seller's own
  stores
- In-app notification bell (Laravel's `database` notification channel); store owners get a live
  notification the moment a buyer places an order or leaves a review
- Category taxonomy validation: the product browse/filter endpoint rejects any `category` value
  that isn't a real `store_categories` row, instead of accepting an arbitrary id silently
- Dark / light mode toggle (Tailwind class-based strategy, persisted per browser)
- Ecosystem SSO from the Dot hub

> **Not yet built:** Stripe payment processing, a vendor payout/commission dashboard, promotional
> codes and timed discounts, structured domain-event dispatch or a Knowledge Pack publisher to
> Dot.Brain, and seller-side product/store management screens (there is still no UI for a seller
> to create or edit a store or product — only the dashboard's read-only view of what already
> exists in the database). These were all present as aspirations in earlier drafts of this
> README but are not in the codebase; see `wiki.md` §7 for the full roadmap.

## Domain Models

- **Store** — vendor storefront; belongs to a `User` owner, has many `Product`
- **StoreCategory** — self-referential category tree
- **Product** — belongs to a `Store` and optional `StoreCategory`; has many `ProductImage`, `Review`
- **ProductImage** — ordered gallery per product
- **Order** — belongs to a buyer `User`; has many `OrderItem`
- **OrderItem** — line item linking an `Order` to a `Product`
- **Review** — one review per (product, user)
- **CartItem** — one row per (user, product); the pre-checkout cart

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
`RefreshDatabase` trait — no shared Postgres instance required to run them.

## Ecosystem

**Dot.Emall** is one of the platforms in the Dot ecosystem, connected via shared PostgreSQL and
Sanctum SSO. Visit [Dot.Brain](https://github.com/sakhilebhayi/Dot.Brain) for the ecosystem-wide
knowledge repo, and [`wiki.md`](wiki.md) for this platform's own source of truth.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
