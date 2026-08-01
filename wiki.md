---
title: Dot.Emall — Platform Wiki
version: 0.3.1
status: active
owners: [Emall Platform Lead]
platform-id: dot-emall
last-review: 2026-08-01
---

# Dot.Emall

Purpose: this is Dot.Emall's own knowledge home — owned and maintained by the Dot.Emall team. It describes what this platform actually does, how it is built, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-emall.md)

---

## 1. What Dot.Emall Is

Dot.Emall is the marketplace platform in the Dot Ecosystem: a multi-vendor storefront application where sellers open a store, list products, and fulfil orders, while buyers shop across every store in the marketplace. It owns the commerce *matching* domain — listings, catalogue, cart, and order flow — not settlement (Dot.Billing), seller finance (Dot.Finance), or auctions (Dot.Auction).

**Status:** working application. The repository is a Laravel 12 codebase with a real schema, models, an owner dashboard, and ecosystem SSO already wired up — this is not a blueprint, it's the current shipped surface. Sections below describe what exists today; the Roadmap (§7) covers what's still ahead.

## 2. Architecture

| Layer | Technology |
|---|---|
| Framework | Laravel 12 (PHP 8.4) |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Auth / accounts | Laravel Jetstream (teams) + Fortify + Sanctum |
| Database | PostgreSQL 16 — shared instance across the Dot Ecosystem (`infodot` database) |
| Realtime | Laravel Reverb |
| Storage | Flysystem (local / S3) |
| AI | Anthropic Claude (`claude-sonnet-4-6`), key configured via `ANTHROPIC_API_KEY` |

Dot.Emall runs against the ecosystem's shared PostgreSQL instance rather than an isolated database — tables are namespaced by name (`stores`, `products`, `orders`, …) within that shared instance, and the app assumes `DB_DATABASE=infodot`.

### Ecosystem SSO

`App\Http\Controllers\Auth\EcosystemAuthController` (`app/Http/Controllers/Auth/EcosystemAuthController.php`) implements the login handoff: a caller hits `GET /auth/ecosystem?token=...` with a Sanctum personal access token scoped `ecosystem:read`; the controller validates the token (exists, has the scope, not expired), logs the tokenable user in, deletes the one-time token, and redirects to `/dashboard`. This is how a user authenticated elsewhere in the Dot Ecosystem lands inside Dot.Emall already signed in.

## 3. Domain Entities

Schema defined in `database/migrations/2026_06_27_000001_create_emall_tables.php`, models in `app/Models/`:

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| `Store` | `stores` | owner_id, name, slug, logo_path, is_active | Vendor storefront; belongs to a `User` owner, has many `Product` |
| `StoreCategory` | `store_categories` | name, slug, parent_id | Self-referential category tree |
| `Product` | `products` | store_id, category_id, name, slug, price, compare_price, stock, sku, is_active | Belongs to a `Store` and optional `StoreCategory`; has many `ProductImage`, `Review` |
| `ProductImage` | `product_images` | product_id, path, sort_order | Ordered gallery per product |
| `Order` | `orders` | user_id, status, subtotal, total, shipping_address | Belongs to buyer `User`; has many `OrderItem` |
| `OrderItem` | `order_items` | order_id, product_id, quantity, unit_price, total | Line item; links `Order` to `Product` |
| `Review` | `reviews` | product_id, user_id, rating, body | One review per (product, user) — unique constraint |
| `CartItem` | `cart_items` | user_id, product_id, quantity | One row per (user, product) — unique constraint |

Order status today is a free-form string on `orders.status` (default `pending`); the dashboard queries recognize at least `pending` and `delivered` as meaningful states (see §4).

## 4. Owner Dashboard

`GET /dashboard` (behind `auth:sanctum` + Jetstream session + `verified` middleware, defined inline in `routes/web.php`) is the seller-facing view: it resolves the stores owned by the current user, the products across those stores, and the orders containing at least one of those products, then surfaces total products, total orders, revenue (`sum(total)` where `status = 'delivered'`), pending order count, and the eight most recent orders with buyer/product/store eager-loaded.

## 5. Events Emitted

The application does not yet publish Knowledge Packs to Dot.Brain, and most state changes are still silent model writes with no event dispatch behind them. One exception now exists: order placement dispatches a real Laravel event.

| Event (conceptual) | Trigger | Current implementation |
|---|---|---|
| Store created/updated | Seller opens or edits a storefront | `Store` model writes; no event dispatch yet |
| Product published/updated | Seller adds or edits a listing | `Product` model writes; no event dispatch yet |
| Order placed | Buyer checks out | `App\Events\OrderPlaced` is dispatched from `CartController::checkout()` once the order transaction commits, carrying the created `Order`. No listener is registered yet — this is the real trigger, not a consumer, and it's the first domain event this codebase actually fires. |
| Order status changed | Fulfilment progresses (pending → delivered, etc.) | `orders.status` column update; no route or controller changes it after creation today, so there is no trigger point to dispatch from yet |
| Review submitted | Buyer rates a product | `Review` model writes; no event dispatch yet |

Formal Knowledge-Pack-facing event names (`commerce.listing.published`, `commerce.order.placed`, etc., matching the naming Dot.Brain expects — see §6) and a listener that turns `OrderPlaced` into a published pack are still on the roadmap. `OrderPlaced` is a plain internal Laravel event, not yet mapped to the ecosystem's dotted event-name convention.

## 6. Connecting to Dot.Brain

Dot.Emall participates in the Dot Ecosystem as a registered platform (`dot-emall`) that will publish Knowledge Packs about its own commerce activity — aggregate demand and fulfilment signals, never individual buyer behavior.

Dot.Brain's ingestion-side document for this platform lays out the intended contract in detail: entity/event mapping, the four Knowledge Pack payload types (`observation`, `insight`, `outcome`, `incident`), the aggregate-only tenancy model (storefront-keyed, n ≥ 20 distinct-buyer floor before any pack leaves the platform), the cross-platform relationships to Dot.Billing/Dot.Auction/Dot.Finance/Dot.Dopemine, and a worked publish → PR → verified-outcome round-trip for the listing-timing recommendation. That document is authoritative for integration mechanics: [`platforms/dot-emall.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-emall.md). This wiki is authoritative for what Dot.Emall actually *is* and what's actually built.

Two things worth flagging for anyone closing that gap: the codebase has no Knowledge Pack publisher, no `platform.dkp.json` manifest, and no domain event dispatch yet — §5's "conceptual" events need to become real dispatched events before any pack can be assembled from them. The category taxonomy Dot.Brain's document assumes (`schemas/taxonomy.json` validation at publish time) has no counterpart in `StoreCategory` today — categories are freeform, seller-created rows.

## 7. Roadmap

- [x] Dispatch a real domain event for order placement — `App\Events\OrderPlaced`, fired from `CartController::checkout()` (no listener/Knowledge-Pack mapping yet)
- [ ] Dispatch the remaining domain events (`commerce.listing.published`, `commerce.listing.unsold_expiry`, review submitted, order status changed, etc.) instead of silent model writes — blocked on the seller product-CRUD and order-status-update controllers that don't exist yet, since there's no real trigger point to dispatch from
- [ ] Build the Knowledge Pack publisher and `platform.dkp.json` manifest (signing key, publishes/subscribes lists, tenancy config)
- [ ] Validate `StoreCategory` terms against the ecosystem's semantic taxonomy at publish time
- [ ] Wire Stripe payment processing and a vendor payout/commission dashboard (both referenced in README aspirations, neither present in the current schema/models)
- [ ] Promotional codes and timed discounts
- [ ] Search integration (Scout/Meilisearch, listed in stack but not yet wired to any controller)
- [ ] Define and enforce the aggregation floor (n ≥ 20 distinct buyers) before any demand-signal pack leaves the platform
- [ ] Auction-eligible listing handoff to Dot.Auction (ownership transfer semantics — resolved on the Dot.Brain side per its `dot-auction.md`, needs an implementation here)

## Open Questions

- Order status is currently an unconstrained string (`pending`, `delivered`, and whatever else gets written) — does it need a formal enum/state machine before event dispatch can be trusted downstream?
- The shared-PostgreSQL-instance model (`DB_DATABASE=infodot` across the ecosystem) — is table-name namespacing sufficient long-term, or does Dot.Emall need schema-level isolation as the ecosystem grows?
- Where does Stripe settlement actually live once implemented — inside Dot.Emall's own tables, or handed off entirely to Dot.Billing at `Order` creation time?

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.2.0 | 2026-08-01 | Emall Platform Lead | Initial wiki: derived from the actual Laravel codebase (schema, models, dashboard, ecosystem SSO controller), cross-referenced against Dot.Brain's platforms/dot-emall.md for ecosystem framing |
| 0.3.0 | 2026-08-01 | Emall Platform Lead | Platform-loop pass: built the buyer-facing product marketplace and the cart -> checkout flow that turns `CartItem` rows into real `Order`/`OrderItem` rows (previously nothing in the codebase ever created an `Order` at all — the "Order placed" row in §5 was purely conceptual until now). Added `ProductImage` model (the relation existed, the class didn't — a live bug). Added `OrderPolicy` and `ReviewPolicy` so the new `/orders` and review routes are scoped to their owners instead of shipping unguarded. Added an in-app notification bell (`database` channel) firing on new order / new review. Added a bounded fix for the category-taxonomy gap: the product browse endpoint now validates any `category` filter against real `store_categories` rows (`exists:store_categories,id`) rather than accepting an arbitrary id — this does not touch the deeper "validate against the ecosystem's semantic taxonomy at publish time" gap from §6, since there is still no product-creation route in this codebase to validate at. Wired the real logo/favicons across nav/auth/browser tab and added a dark/light toggle. Domain event dispatch, the Knowledge Pack publisher, Stripe/payouts, and seller-side store/product management screens remain roadmap — see §7, unchanged in scope by this pass. |
| 0.3.1 | 2026-08-01 | Emall Platform Lead | Incremental pass: dispatched Dot.Emall's first real domain event, `App\Events\OrderPlaced` (`app/Events/OrderPlaced.php`), fired from `CartController::checkout()` after the order transaction commits. Scoped deliberately narrow — the other roadmap events (listing published, order status changed, review submitted) still have no real controller trigger to dispatch from (no seller product-CRUD, no order-status-update route exist yet), so adding events for them now would mean firing something with no genuine cause, which this platform's engineering loop treats as fabrication. No listener is registered yet; this is the trigger a future Knowledge Pack publisher will subscribe to. Added `test_checkout_dispatches_order_placed_for_the_created_order` and `test_checkout_does_not_dispatch_order_placed_when_stock_is_insufficient` to `tests/Feature/Marketplace/CartCheckoutTest.php` (written, not executed — no local PHP/Composer/Postgres). §5 and §7 updated to match. |
