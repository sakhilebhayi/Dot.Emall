---
title: Dot.Emall — Platform Wiki
version: 0.6.0
status: active
owners: [Emall Platform Lead]
platform-id: dot-emall
last-review: 2026-08-04
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
| `CartItem` | `cart_items` | user_id, product_id, quantity | One row per (user, product) — unique constraint; only tenant-owned model scoped by `HasUserScope` (see §8) |

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

**Dot.Emall published its first real, signed DKP pack on 2026-08-02** — the second platform in the ecosystem to clear DKP onboarding step 1 (Dot.Billing was first; see [Dot.Billing's wiki.md](https://github.com/sakhilebhayi/Dot.Billing/blob/main/wiki.md) §7), proving the recipe from Dot.Brain's [`os/05-Knowledge-Protocol.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/os/05-Knowledge-Protocol.md) §5 is repeatable, not a one-off:

- [`platform.dkp.json`](platform.dkp.json) — validated by hand against Dot.Brain's `schemas/platform-manifest.schema.json`
- A real Ed25519 keypair — public half in the manifest, private half at `storage/app/private/dkp-signing.key`, gitignored
- [`app/Console/Commands/PublishDkpIncidentPack.php`](app/Console/Commands/PublishDkpIncidentPack.php) — writes up the real, already-fixed checkout stock-race incident (§5, this platform's `wiki.md` 0.3.0/0.3.1) as a genuine `incident_report` payload per `schemas/incident.schema.json`, not another illustrative example
- `storage/app/dkp/packs/` — one real signed pack, run for real against a live PostgreSQL database, and independently re-verified against the committed public key outside the PHP process. Ran clean on the first try — no bugs, unlike Dot.Billing's first attempt (a config bug caught the same way), because this pass applied that lesson from the start.

It still doesn't transmit anywhere — DKP's transport layer is unbuilt ecosystem-wide — and no domain event dispatch beyond `OrderPlaced` exists yet, so the other three payload types (§5's remaining "conceptual" events) still can't be assembled. The category taxonomy Dot.Brain's document assumes (`schemas/taxonomy.json` validation at publish time) has no counterpart in `StoreCategory` today — categories are freeform, seller-created rows.

## 7. Roadmap

- [x] Dispatch a real domain event for order placement — `App\Events\OrderPlaced`, fired from `CartController::checkout()` (no listener/Knowledge-Pack mapping yet)
- [ ] Dispatch the remaining domain events (`commerce.listing.published`, `commerce.listing.unsold_expiry`, review submitted, order status changed, etc.) instead of silent model writes — blocked on the seller product-CRUD and order-status-update controllers that don't exist yet, since there's no real trigger point to dispatch from
- [x] ~~Build the Knowledge Pack publisher and `platform.dkp.json` manifest~~ — done 2026-08-02 for the `incident_report` payload type (§6); the `observation`/`insight`/`outcome` types still need real event dispatch first
- [ ] Validate `StoreCategory` terms against the ecosystem's semantic taxonomy at publish time
- [ ] Wire Stripe payment processing and a vendor payout/commission dashboard (both referenced in README aspirations, neither present in the current schema/models)
- [ ] Promotional codes and timed discounts
- [ ] Search integration (Scout/Meilisearch, listed in stack but not yet wired to any controller)
- [ ] Define and enforce the aggregation floor (n ≥ 20 distinct buyers) before any demand-signal pack leaves the platform
- [ ] Auction-eligible listing handoff to Dot.Auction (ownership transfer semantics — resolved on the Dot.Brain side per its `dot-auction.md`, needs an implementation here)

## 8. Tenant Scoping (`HasUserScope`)

Dot.Emall is a two-sided marketplace, not a single-tenant app, so most models are *deliberately* readable across users: a buyer views another user's store, product, and reviews; a seller's dashboard (§4) reads `Order`/`OrderItem` rows placed by other users that merely contain the seller's own products. Blindly applying a per-user global scope to `Store`, `Product`, `Order`, `Review`, etc. would silently break those real, intended cross-user reads rather than fix a leak.

`CartItem` is the one exception: a shopping cart has no legitimate cross-user read path anywhere in this codebase — it is only ever created, listed, or deleted by its own owner. `App\Models\Concerns\HasUserScope` (`app/Models/Concerns/HasUserScope.php`) is the direct analog of Dot.Finance's `HasUserScope` / Dot.Notify's `HasTeamScope`: an Eloquent global scope that filters every query against the model to `Auth::id()` while a user is authenticated, so a forgotten `where('user_id', ...)` in a future controller or Livewire component can no longer leak another buyer's cart. `CartItem` applies it; mass-assignment still sets `user_id` explicitly at create time (`CartController::store()`, `checkout()`), since the scope only governs reads.

Note this platform does **not** use `HasTeamScope` despite Jetstream's `teams` feature being enabled in `config/jetstream.php` — `Team`/`TeamInvitation`/`Membership` are present as Jetstream account-management boilerplate only. Seller ownership runs through `stores.owner_id` → `users.id` directly, not through any `team_id` column; no tenant-owned table in this schema has a `team_id` at all (verified against `database/migrations/2026_06_27_000001_create_emall_tables.php`), so the team-scoped variant of the pattern does not apply here.

**Effect on existing behavior:** `CartController::index()` and `checkout()` no longer need an explicit `where('user_id', auth()->id())` filter on `CartItem` queries — removed as redundant now that the model scopes itself. `CartController::destroy()`'s implicit `{cartItem}` route-model binding is scoped the same way, so a cart item belonging to another user is invisible to the binding query before the controller body runs — the old `abort_unless($cartItem->user_id === auth()->id(), 403)` guard is gone, and the cross-user case now 404s instead of 403ing (`tests/Feature/Marketplace/CartCheckoutTest.php::test_a_user_cannot_remove_another_users_cart_item`, updated to `assertNotFound()`). This is the same fail-closed-at-the-query-layer shift documented on Dot.Finance's `HasUserScope`/`FinanceAuthorizationTest`. `tests/Feature/Marketplace/CartItemScopeTest.php::test_scope_alone_blocks_cross_user_access_even_without_a_policy_check` proves the scope itself is load-bearing, independent of any controller check.

## Open Questions

- Order status is currently an unconstrained string (`pending`, `delivered`, and whatever else gets written) — does it need a formal enum/state machine before event dispatch can be trusted downstream?
- The shared-PostgreSQL-instance model (`DB_DATABASE=infodot` across the ecosystem) — is table-name namespacing sufficient long-term, or does Dot.Emall need schema-level isolation as the ecosystem grows?
- Where does Stripe settlement actually live once implemented — inside Dot.Emall's own tables, or handed off entirely to Dot.Billing at `Order` creation time?

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.6.0 | 2026-08-04 | Platform-loop pass | **Tenant-scoping hardening pass**, following the pattern established on Dot.Finance (`HasUserScope`, single-user) and Dot.Notify (`HasTeamScope`, Jetstream-teams). Reviewed the existing `app/Models/Concerns/HasUserScope.php` trait skeleton (already correctly structured — `addGlobalScope`, `Auth::check()`, scoped on `getTable().'.'.userScopeColumn()`) and confirmed its reasoning against the real schema: `Store`/`Product`/`Order`/`Review` are genuinely cross-user-readable (buyer browsing, seller dashboard reading other buyers' orders) and must stay unscoped; `CartItem` is the only tenant-owned model with no legitimate cross-user read path, so it alone gets the trait. Confirmed the single-user (`user_id`) pattern is correct over the teams variant — despite Jetstream `teams` being enabled in config, no tenant-owned table has a `team_id` column; `Team`/`TeamInvitation`/`Membership` are unused Jetstream account boilerplate, and seller ownership runs through `stores.owner_id` directly. Applied `HasUserScope` to `CartItem`; simplified `CartController::index()`/`checkout()` to drop now-redundant `where('user_id', ...)` filters and removed the now-superseded `abort_unless(...)` 403 guard in `destroy()` (route-model binding now 404s on cross-user access instead — `CartCheckoutTest::test_a_user_cannot_remove_another_users_cart_item` updated to `assertNotFound()`). Added `tests/Feature/Marketplace/CartItemScopeTest.php` proving the scope alone blocks cross-user access with no policy/explicit-where involved. Full suite green against real PostgreSQL (`dot_emall_pilot`): 77 tests, 70 passed, 7 skipped, 0 failed — before and after the guzzle bump below. Added `phpstan`/`larastan` (level 5, `phpstan.neon.dist`) — ran successfully (not a sandbox no-op) and surfaced 19 pre-existing type errors, none introduced by this pass (Jetstream model PHPDoc/generics gaps predating this work; left as-is, out of scope). `composer audit` found 6 guzzlehttp/guzzle advisories (CVE-2026-69246/69245/67354/67355/67353/67339); fixed via `composer update guzzlehttp/guzzle guzzlehttp/psr7 guzzlehttp/promises --with-all-dependencies` (7.12.3 → 7.15.2), re-audit clean, full suite re-run green. See §8 for the scoping design writeup. |
| 0.5.0 | 2026-08-03 | Sakhile Bhayi | **Built a real marketing welcome page** — `resources/views/welcome.blade.php` had never been customized; it was still the stock Laravel 12 scaffold (Laravel wordmark SVG, generic "Let's get started" two-panel layout, no marketing copy about Dot.Emall at all). Replaced it with a full custom page structurally matching the pattern already shipped on Dot.Mines: fixed-header nav, a two-column hero (headline/subhead/CTA on one side, a store-owner-dashboard mock card on the other), a features grid, a capabilities section, a "cart to delivered order" flow section, a final CTA, and a footer — all using the real `public/images/logo.png` in the nav and footer. Copy is drawn directly from this file's own domain description (§1-§6): the features grid describes the actual `Store`, `Product`/`ProductImage`, `CartItem`, `Order`/`OrderItem`, `Review`, and `StoreCategory` models and the real stock-safe checkout transaction, not fabricated marketing claims — no invented stats, feature counts, or social proof. Hero background photo: warehouse shelves full of boxes by Brayden Prato (@pratophoto), unsplash.com/photos/a-warehouse-filled-with-lots-of-shelves-filled-with-boxes-If5vloAJSBQ. CTA section background photo: cardboard boxes and packaging supplies in a warehouse by Guilherme Mendes (@guilhermemendes), unsplash.com/photos/cardboard-boxes-and-packaging-supplies-in-a-warehouse-RVP3wAw9gJY. Both direct `images.unsplash.com` CDN URLs verified via `curl -sI` returning `HTTP/2 200` before use. Dark gradient overlays added over both photos for text contrast, matching Mines' approach. `routes/web.php`'s `/` route already pointed at `welcome` — no routing change needed. |
| 0.4.0 | 2026-08-02 | Sakhile Bhayi | **DKP onboarding step 1 for real** (§6): the ecosystem's second real signed pack, after Dot.Billing's. Real Ed25519 keypair, `platform.dkp.json` validated against Dot.Brain's `schemas/platform-manifest.schema.json`, `app/Console/Commands/PublishDkpIncidentPack.php` writing up the real checkout stock-race incident as a genuine `incident_report` payload, one real signed pack committed and independently re-verified. Also executed this codebase for real for the first time this session: `composer install`, `migrate` (10 migrations, clean), and the full test suite (76 total, 69 passed, 7 skipped by config, 0 failed) all ran clean against real PostgreSQL — including the checkout-flow tests covering the stock-race fix. Guarded the six shared Jetstream-core migrations per Dot.Brain adr/ADR-0013. |
| 0.2.0 | 2026-08-01 | Emall Platform Lead | Initial wiki: derived from the actual Laravel codebase (schema, models, dashboard, ecosystem SSO controller), cross-referenced against Dot.Brain's platforms/dot-emall.md for ecosystem framing |
| 0.3.0 | 2026-08-01 | Emall Platform Lead | Platform-loop pass: built the buyer-facing product marketplace and the cart -> checkout flow that turns `CartItem` rows into real `Order`/`OrderItem` rows (previously nothing in the codebase ever created an `Order` at all — the "Order placed" row in §5 was purely conceptual until now). Added `ProductImage` model (the relation existed, the class didn't — a live bug). Added `OrderPolicy` and `ReviewPolicy` so the new `/orders` and review routes are scoped to their owners instead of shipping unguarded. Added an in-app notification bell (`database` channel) firing on new order / new review. Added a bounded fix for the category-taxonomy gap: the product browse endpoint now validates any `category` filter against real `store_categories` rows (`exists:store_categories,id`) rather than accepting an arbitrary id — this does not touch the deeper "validate against the ecosystem's semantic taxonomy at publish time" gap from §6, since there is still no product-creation route in this codebase to validate at. Wired the real logo/favicons across nav/auth/browser tab and added a dark/light toggle. Domain event dispatch, the Knowledge Pack publisher, Stripe/payouts, and seller-side store/product management screens remain roadmap — see §7, unchanged in scope by this pass. |
| 0.3.1 | 2026-08-01 | Emall Platform Lead | Incremental pass: dispatched Dot.Emall's first real domain event, `App\Events\OrderPlaced` (`app/Events/OrderPlaced.php`), fired from `CartController::checkout()` after the order transaction commits. Scoped deliberately narrow — the other roadmap events (listing published, order status changed, review submitted) still have no real controller trigger to dispatch from (no seller product-CRUD, no order-status-update route exist yet), so adding events for them now would mean firing something with no genuine cause, which this platform's engineering loop treats as fabrication. No listener is registered yet; this is the trigger a future Knowledge Pack publisher will subscribe to. Added `test_checkout_dispatches_order_placed_for_the_created_order` and `test_checkout_does_not_dispatch_order_placed_when_stock_is_insufficient` to `tests/Feature/Marketplace/CartCheckoutTest.php` (written, not executed — no local PHP/Composer/Postgres). §5 and §7 updated to match. |
