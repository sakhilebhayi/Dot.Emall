# Dot.Emall: Seller Onboarding + Product Listing Moderation Gate

## Context

Dot.Emall's autonomy classification audit (`Dot.Brain/platforms/dot-emall.md`, 2026-08-08) found no real Level 2 candidate in the codebase — checkout, order/review notifications, and everything else Level 1 are synchronous side effects of a single HTTP request, and the one thing that looked adjacent (`dkp:publish-incident`) is a hand-run script, not an automated proposal awaiting approval.

Direct inspection of the real codebase (`~/Dot/Dot.Emall`) found the gap is deeper than "no Level 2 process exists": **no code anywhere creates a `Store` or `Product`** — `grep -rn "Store::create\|Product::create" app/ database/seeders/` returns nothing outside tests. There is no seller-onboarding or listing-creation flow at all today; sellers cannot list anything. This mirrors exactly what Dot.Ehail's driver-application gap turned out to be.

This spec builds the missing flow, plus the real Level 2 gate it unblocks: a marketplace where anyone can submit a product listing needs independent review before that listing reaches buyers — the platform has no existing operator/admin concept at all (unlike Dot.Mines/ChartSense's DKP work, which already had `is_platform_operator`), so this spec introduces it, following that exact precedent.

## Goal

Any authenticated user can become a seller (create a `Store`, active immediately — becoming a seller is just identity, not risk) and submit product listings to it. A new listing starts `pending_approval` and is invisible to buyers. A platform operator (a new `is_platform_operator` flag, granted by hand — no self-serve path) reviews pending listings and approves or rejects them; rejecting requires a reason. Nothing about checkout, reviews, or existing browsable products changes.

## Changes

### 1. `is_platform_operator` flag

New migration on `users`: `boolean('is_platform_operator')->default(false)`, excluded from `User::$fillable` — mass-assignment cannot set it regardless of field name, matching ChartSense's `2026_08_08_172255_add_is_platform_operator_to_users_table.php` exactly (same column name, same "why not `is_admin`" reasoning: a generic admin-flag name is an obvious mass-assignment probe target). No route or command in this codebase ever grants it; an operator is created by hand (`php artisan tinker`) exactly like ChartSense's operators are today.

### 2. Seller onboarding (`StoreController`)

New controller, `app/Http/Controllers/StoreController.php`, matching this app's existing plain-controller convention (`ProductController`, `CartController`, `OrderController`):

- `create(): View` — the "become a seller" form. Route `GET /sell`, name `seller.store.create`, behind the existing `auth:sanctum` + session + `verified` middleware group. If the current user already owns a store, redirects to it instead of showing the form (idempotent, not an error).
- `store(Request $request): RedirectResponse` — Route `POST /sell`, name `seller.store.store`, same middleware. Validates `name` (required, string, max 255) and `description` (nullable, string, max 2000). Refuses (flash error, redirect back) if the user already owns a store — application-level check (`Store::where('owner_id', auth()->id())->exists()`), the same shape as `DriverApplicationController`'s existing-profile check; no DB unique constraint is added since nothing today creates a second store for anyone and the existing `stores` table schema is left untouched. Creates a `Store` with `owner_id: auth()->id()`, a slugified `name`, and `is_active` left at its column default (`true`) — the store is browsable/sellable-from immediately. Redirects to the new seller dashboard (`seller.products.create`) with a success flash message.

### 3. `products` gains a review lifecycle

Migration: add `status` (string, default `'pending_approval'`), `rejected_reason` (nullable text), `reviewed_by` (nullable FK `users.id`, `nullOnDelete`), `reviewed_at` (nullable timestamp) to `products`. The existing `is_active` column is untouched — it already gates buyer visibility via `Product::scopeBrowsable()` and nothing about its meaning changes; `status` is a second, independent gate layered on top, not a replacement (unlike Dot.docs' webhook migration, which replaced its boolean outright — that replacement isn't warranted here because `is_active` has call sites this spec doesn't otherwise need to touch: `ProductController::show`'s `abort_unless`, `CartController::checkout`'s stock re-check, and `resources/views/products/show.blade.php`). `Product::$fillable` and casts updated to match; no new relation methods needed beyond a `reviewer(): BelongsTo` matching this session's established pattern (Dot.Ehail's `DriverProfile::reviewer()`, ChartSense's `KnowledgePack` reviewer field).

### 4. Product listing submission (`SellerProductController`)

New controller, `app/Http/Controllers/SellerProductController.php`:

- `create(): View` — the listing form. Route `GET /sell/products/create`, name `seller.products.create`, same middleware group. Requires the user to own a store (redirects to `seller.store.create` if not — the two flows chain naturally). Shows a `StoreCategory::all()` dropdown (optional — `category_id` stays nullable, matching the existing schema; no category seed data exists today, so an empty category list must not block submission).
- `store(Request $request): RedirectResponse` — Route `POST /sell/products`, name `seller.products.store`, same middleware. Requires store ownership (403 via a `Gate::authorize('create', [Product::class, $store])` check — see §5). Validates `name` (required, string, max 255), `description` (nullable, string, max 2000), `price` (required, numeric, min 0), `compare_price` (nullable, numeric, min 0), `stock` (required, integer, min 0), `sku` (nullable, string, unique on `products`), `category_id` (nullable, exists:store_categories,id). Creates a `Product` with `store_id` the current user's store, `status: 'pending_approval'`, a slugified `name`. No image upload — `ProductImage` stays exactly as unused as it is today; this is an explicit, named gap (out of scope §7), not a silent one. Redirects to `products.show` for the new (not-yet-visible-to-others) listing with a flash message explaining it's awaiting review.

### 5. `ProductPolicy` (new)

New policy, `app/Policies/ProductPolicy.php`, matching `OrderPolicy`/`ReviewPolicy`'s existing convention:

- `create(User $user, Store $store): bool` — `$user->id === $store->owner_id`. Used by `SellerProductController::store()`.

`ProductController::show()`'s existing `abort_unless($isOwner || ($product->is_active && $product->store->is_active), 404)` gains a `status === 'approved'` condition for the non-owner branch — a store owner can always preview their own pending/rejected listing; nobody else can see a listing that isn't both `is_active` and `status === 'approved'` (in addition to the existing store-active check).

### 6. Review screen (`ProductModerationController`)

New controller, `app/Http/Controllers/ProductModerationController.php`, and new middleware `app/Http/Middleware/EnsurePlatformOperator.php` (web variant of ChartSense's — redirect-with-flash-error via `abort(403)`, not a JSON response, matching this app's web-only shape):

- `index(): View` — Route `GET /operator/products`, name `operator.products.index`, behind `auth:sanctum` + `verified` + the new `EnsurePlatformOperator` middleware. Lists `Product::where('status', 'pending_approval')->with(['store.owner'])->latest()->get()`, plus a reviewed section (`approved`/`rejected`) for context.
- `approve(Product $product): RedirectResponse` — Route `POST /operator/products/{product}/approve`. Refuses (flash error, redirect back) unless `$product->status === 'pending_approval'`. Sets `status: 'approved'`, `reviewed_by: auth()->id()`, `reviewed_at: now()`.
- `reject(Product $product, Request $request): RedirectResponse` — Route `POST /operator/products/{product}/reject`. Same pending-only guard, plus validates `reason` as `required|string` — rejecting with an empty reason is a validation failure, not silently accepted. Sets `status: 'rejected'`, `rejected_reason`, `reviewed_by`, `reviewed_at`.

Unlike Dot.Ehail's `FleetController` (which had to route around `HasUserScope`'s implicit-binding trap), `Product` carries no user-scoping trait, so ordinary `{product}` route-model binding is safe to use directly here — there is no equivalent trap in this codebase.

### 7. `Product::scopeBrowsable()` gains the status gate

```php
public function scopeBrowsable(Builder $query): Builder
{
    return $query->where('is_active', true)
        ->where('status', 'approved')
        ->whereHas('store', fn (Builder $q) => $q->where('is_active', true));
}
```

`CartController::store()`'s `Product::browsable()->findOrFail($validated['product_id'])` call already routes through this scope, so a `pending_approval`/`rejected` product 404s out of cart-add for free — no separate enforcement point needed. `CartController::checkout()`'s in-transaction re-check (`! $product || ! $product->is_active || $item->quantity > $product->stock`) gains a `|| $product->status !== 'approved'` condition for defense in depth, matching this method's existing "re-check inside the transaction, don't trust the pre-check" posture — a listing could theoretically be rejected after being added to cart but before checkout completes.

### 8. Pre-existing test fixture fix

`tests/Feature/Marketplace/ProductBrowseTest.php`'s `makeProduct()` helper creates products with no `status` field. Once `status` defaults to `pending_approval`, every existing browse test that expects a product to be visible breaks — the same class of regression Dot.Ehail's `DashboardTest`/`DriverProfileViewTest` hit when `fleet_id` became required. Fix: add `'status' => 'approved'` to `makeProduct()`'s defaults.

## Testing

New test files, matching this app's existing `tests/Feature/Marketplace/*` convention (`RefreshDatabase`, hand-built fixture helpers, no factories for `Store`/`Product` — none exist in this codebase and this spec doesn't add them, following `ProductBrowseTest`'s existing precedent of building fixtures with `::create()` directly):

- `tests/Feature/Marketplace/BecomeSellerTest.php` — submitting the become-a-seller form creates an active `Store` owned by the current user; a user who already owns a store is redirected to it instead of creating a second one; a guest is redirected to login.
- `tests/Feature/Marketplace/ProductListingTest.php` — a store owner can submit a listing and it lands `pending_approval`; a user with no store cannot reach the listing form (redirected to become-a-seller); a user cannot submit a listing for a store they don't own (403); a `pending_approval` product does not appear in `products.index`, 404s at `products.show` for a non-owner, is visible at `products.show` to its owner, and cannot be added to cart; a `rejected` product behaves the same as `pending_approval` for all of the above.
- `tests/Feature/Marketplace/ProductModerationTest.php` — an operator can approve a pending product (status flips to `approved`, `reviewed_by`/`reviewed_at` set, and it becomes immediately browsable); an operator can reject with a reason (status flips to `rejected`, `rejected_reason` set); rejecting without a reason is blocked and status stays `pending_approval`; a non-operator is refused access to `/operator/products` and its actions (403); approving/rejecting an already-decided product is refused and its state doesn't change.
- Fix to `tests/Feature/Marketplace/ProductBrowseTest.php`'s `makeProduct()` helper (add `'status' => 'approved'`) — regression, not new coverage; existing test bodies are otherwise untouched.

## Explicitly out of scope

- Product image upload — `ProductImage`/`product_images` stay exactly as unused as they are today. A seller creates a listing with no photos; this is a named, not silent, gap.
- Editing or withdrawing a submitted listing — only submit, approve, reject exist, matching Dot.Ehail's identical exclusion for driver applications.
- Re-review of an already-approved listing (e.g. a seller editing price after approval re-triggering moderation) — out of scope; approval is a one-time gate on creation only.
- Un-rejecting a rejected listing, or suspending an already-approved one — no UI or transition exists for either, on either side of this change.
- Multi-admin stores (more than one user managing a given `Store`) — YAGNI, matching Dot.Ehail's identical exclusion for fleets.
- Store-level approval — per this session's design discussion, a `Store` activates immediately on creation; only individual `Product` listings are gated.
- Notifying the seller of the approve/reject decision — only the operator dashboard shows the outcome; the seller finding out by revisiting their own listing is the honest current state, not silently expanded (matches Dot.Ehail's identical exclusion for the applicant).
- A self-serve path to becoming a platform operator — granted by hand only, matching ChartSense's existing precedent exactly.
- Registering this change in Dot.Brain's `platforms/dot-emall.md` or `platforms/autonomy-signals.json` — a separate, future re-audit pass, not part of building the feature.

## Open question resolved during design

Who reviews new listings, given this app has no operator/admin concept at all? Resolved: introduce `is_platform_operator`, matching the ChartSense/Dot.Mines DKP-work precedent exactly, rather than having a store owner self-approve (which would not be genuine independent review) or inventing a bespoke moderator concept.
