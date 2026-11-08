# R-43 backend and commerce-engineering audit (Dot.Emall)

Branch `feat/r43-be0`, 2026-11-08. Laravel 13.37, PHP 8.5 (CI 8.4), Livewire 3.8, Jetstream/Fortify/Sanctum 4.3, Paystack. Production database PostgreSQL 16, tests SQLite.
Scope: backend only (no views, no seeders; the UX agent owns `feat/r43-ux0`). Every defect below was reproduced by a failing test or script before it was fixed; the regression test names are in the table.

## 1. Executive summary

The money and inventory core is better than most marketplaces at this stage. I attacked it with real parallel PHP processes on PostgreSQL (ten buyers for the last unit, twelve buyers of two with stock five, a promo code with one use left, the webhook and the callback racing on one charge, three simultaneous cancellations of one paid line) and it held every time: no oversell, no negative stock, one promo redemption, one charge applied once, one restock, one refund owed. Prices are never taken from the browser, money is integer cents with largest-remainder allocation, the Paystack webhook is HMAC-checked on the raw body and every confirmation is matched on reference, amount and currency, and every outcome lands in an audit table with no card data. The authorisation matrix (buyer, other buyer, seller, other seller, operator; 12 order, payment, listing and operator endpoints each) had no IDOR.

The defects were at the edges, and most only exist on PostgreSQL, which is what production runs and the suite did not:

- **Search was broken in production**: `LIKE` is case sensitive on PostgreSQL, so searching "samsung" found nothing for "Samsung Galaxy Phone".
- **A seller editing only a price could oversell**: the edit form re-posts the stock number it was drawn with and overwrote every sale made since.
- **A single out-of-range number was a 500** on PostgreSQL (price, compare price, stock, promo value, max uses); a 3-decimal price silently became a free listing.
- **Deleting an account deleted paid orders**, the stock behind them, and stranded the other side's lines.
- A forged Host header was copied into the password-reset email link and the Paystack callback URL.
- The scheduler lock on `orders:expire-unpaid` (the job that releases held stock) lasted 24 hours by default.

13 findings are fixed here with regression tests. The things that need the owner are product rules, not bugs: there are no seller payouts, no VAT, no shipping fees, no tracking, no returns, and with no Paystack key in `.env` the shop quietly takes unpaid orders that sellers can ship (section 4).

Counts: Critical 1 (roadmap, not a bug: no payouts), High 6 (2 fixed, 4 open), Medium 16 (6 fixed, 10 open), Low 11 (4 fixed, 7 open), Info 3 (1 fixed, 2 open).

Verification: SQLite 671 tests, 659 passed, 12 skipped (7 pre-existing, 5 PostgreSQL-only), 0 failed. PostgreSQL 671 tests, 664 passed, 7 skipped, 0 failed. Baseline was 639 tests. Pint clean on every file I touched. `tests/Support/signed-in-smoke.sh` (route, config and event caches on) 34 pages, 0 failed, exit 0.

## 2. Findings

Severity is about money, data and uptime in production. "Status": Fixed = in this branch with a regression test; Open = recommendation. Effort is for the open items (S under a day, M a few days, L a week or more).

| Id | Sev | Area | Evidence / repro | Fix | Status | Effort |
|---|---|---|---|---|---|---|
| F-01 | High | Inventory | Seller opens the edit form (stock 5), a buyer takes 3 (stock 2), the seller saves a new price: stock is 5 again. Probe: "stock after sale 2, after seller price-only edit 5". `BackendAuditTest::test_a_price_only_edit_does_not_resurrect_units_sold_while_the_form_was_open` and 3 siblings | `edit()` remembers the stock the form showed; `update()` applies the seller's change (typed minus shown) to today's stock under a row lock, never below 0, and says so. A hidden `stock_was` field in the view would be sturdier (see R-18) | Fixed | |
| F-02 | High | Search / PG parity | PostgreSQL: `/products?q=samsung` finds nothing for "Samsung Galaxy Phone" (SQLite finds it). `%` and `_` typed by a buyer acted as wildcards. `test_the_product_search_ignores_case`, `test_a_percent_or_underscore_in_the_search_is_matched_literally` | `ilike` on PostgreSQL, `like` elsewhere, `ESCAPE '\'`, wildcards escaped | Fixed | |
| F-03 | Medium | Validation / PG parity | PostgreSQL 500 `numeric field overflow` / `integer out of range` for price 1e10, compare_price 1e14, stock 3e9, promo value 1e9, max_uses 5e9; checkout of 99 x R1e9 overflows `orders.total`. Price `0.001` stored as a free R0.00 listing. `test_prices_and_stock_beyond_the_columns_are_validation_errors_not_server_errors`, `test_promo_values_beyond_the_columns_are_validation_errors` | `max` (R10,000,000 price; 10,000,000 stock and uses) and `decimal:0,2` on the listing and promo forms | Fixed | |
| F-04 | Medium | Data integrity | Deleting a buyer with a paid order: order and lines gone, the payment orphaned (`order_id` NULL), 2 units never restocked, no refund. Deleting a seller: buyer's pending line keeps `seller_id` NULL and `product_id` NULL, nobody can ship it; listing photo files stay on disk. 4 tests in `BackendAuditTest` | `DeleteUser` refuses while the person has open lines on a paid order, an order on its way, or a refund not paid back; archives the seller's listings through the model so photos are purged | Fixed (retention rule open, R-12) | |
| F-05 | Medium | Scheduler | `Schedule::command('orders:expire-unpaid')->withoutOverlapping()` keeps its lock 1440 minutes; a run killed on the shared host blocks the stock-releasing sweep for a day. `test_every_scheduled_task_releases_its_overlap_lock_within_the_hour` | 15 and 30 minute locks | Fixed | |
| F-06 | Medium | Security | A POST to `/forgot-password` with `Host: evil.example` produced the reset link `https://evil.example/reset-password/<token>`; the same host builds the Paystack `callback_url`. Sibling Dot.Auction pins hosts (its F-19) | `TrustedHostList` + `trustHosts()`: production only, host of `APP_URL` unless `TRUSTED_HOSTS` lists aliases, `/up` exempt. Proven in `signed-in-smoke.sh` (evil host 400, evil host `/up` 200) | Fixed. **Owner: set `TRUSTED_HOSTS` if the app is also reached on `emall.infodot.app`** | |
| F-07 | Medium | Performance | Seller dashboard loaded every delivered line into PHP plus an `IN (...)` of every order id: 1,425 ms at 50k delivered lines (PostgreSQL, synthetic). `test_the_seller_revenue_matches_the_line_by_line_sum_...` | One grouped SQL sum with the same cents arithmetic as `OrderItem::netCents()`: 131 ms | Fixed | |
| F-08 | Medium | Performance | 14 foreign keys without an index (PostgreSQL and SQLite do not index them): `product_images.product_id` (read by every listing page), `products.category_id` (the category filter), `refunds.payment_id` (summed on every refund), `order_items.store_id`, `reviews.user_id`, `cart_items.product_id`, `payment_events.*`, `orders.promo_code_id` | Migration `2026_11_08_100000_add_foreign_key_indexes`, create-if-absent. `test_the_foreign_key_indexes_exist` | Fixed | |
| F-09 | Low | Auth / PG parity | `Bearer 99999999999999999999\|abc` on `/api/user` and a made-up SSO handoff token `\|` / `x\|y` hit a bigint column: 500 on PostgreSQL (`InfoDotRedeemTest` failed there) | `App\Models\PersonalAccessToken::findToken` rejects a non-numeric or over-long id first; SSO door uses the registered model | Fixed | |
| F-10 | Low | Cart | Two "Add to cart" requests for the same product at once: the loser hits the unique `(user_id, product_id)` index and answers 500. `test_adding_the_same_product_twice_at_once_adds_on_top_instead_of_failing` | `insertOrIgnore`, then add on top of the winner's line | Fixed | |
| F-11 | Low | Catalogue | Category counts in the filter counted pending, rejected, switched-off and closed-store listings (a hidden-catalogue leak and a wrong number). `test_category_counts_only_count_what_a_buyer_can_see` | Counted with `browsable()` | Fixed | |
| F-12 | Low | Test health | Two tests only passed on SQLite (a 41-char `shipping_phone` sentinel for a `varchar(40)`; `Refund::sum()` is a string on PG). A production-mode test switched on Symfony's static trusted-host list for every later test | Fixed; `TestCase::tearDown` resets it; two migration rollback tests no longer assume their migration is the newest | Fixed | |
| F-13 | Info | Docs | README and wiki said Laravel 12 (installed 13.37); `deploy.yml` header said "schedules nothing" while two tasks and a queued job exist | Corrected | Fixed | |
| R-01 | Critical (roadmap) | Payments | Paystack settles into the platform account. No payout, split, commission, fee, settlement or seller-ledger model or column; the dashboard says "earned, not paid out" | Seller ledger (immutable entries), commission rule per store/category, payout batches, KYC; Paystack subaccounts/splits or transfers | Open | L |
| R-02 | High | Payments / go-live | With no `PAYSTACK_SECRET_KEY` (today's `.env.example`), `requires_payment=false`: orders are placed "pending, unpaid", sellers are told "(unpaid)" and may ship, `Order::isFulfillable()` is true. Fine for a demo, a loss the day it is public | Production gate: refuse checkout (or refuse to start) when the gateway is not configured, unless an explicit `SHOP_ALLOW_UNPAID_ORDERS=true` | Open, owner decision | S |
| R-03 | High | Pricing | Total = items minus one store promo. No VAT (15% in ZA; VAT-registered sellers must issue tax invoices), no shipping fee or rate, no per-line tax fields, no invoice number | Price-includes-VAT flag per store, VAT breakdown stored on lines (cents), shipping profiles (flat/weight/free-over), invoice numbering | Open | L |
| R-04 | High | Payments ops | Money that arrives for a cancelled order, or in a mismatched amount, becomes `needs_review`. The operator desk can only annotate it ("Review recorded"), there is no "refund this payment". Reproduced: charge racing `cancelUnpaidOrder` x4 rounds, all 4 ended `order=cancelled, payment needs_review`, money held | A `RefundService::refundWholePayment($payment)` action on the desk, reusing the two-step refund | Open | S |
| R-05 | High | Inventory | Stock is taken when the order is created and held up to 60 minutes. One buyer can hold a competitor's whole stock (10 checkouts/min allowed, cart line up to 99) and repeat when it expires | Cap open unpaid orders per buyer (3), shorten window (15-30 min), release on abandon | Open, owner decision | S |
| R-06 | Medium | Payments | The 60-minute window runs from order creation, not from the last payment attempt: a buyer who starts paying at minute 59 can be cancelled at minute 60 and their money lands in `needs_review` (R-04) | Measure from `max(order.created_at, last attempt)`; extend while an attempt is pending | Open | S |
| R-07 | Medium | Pricing | Cart shows live price, checkout charges the live price, no confirmation if the seller changed it in between. Not covert (Paystack shows the amount) but unfair | Hidden `expected_total` on the checkout form; refuse with "prices changed" on mismatch | Open (needs a view field) | S |
| R-08 | Medium | Order lifecycle | `Pending -> Delivered` is allowed for a seller in one click; Delivered unlocks reviews and counts as revenue. No proof of shipment | Require Shipped first, store carrier/tracking, buyer confirmation or carrier webhook before revenue is final | Open, owner decision | M |
| R-09 | Medium | Refunds | `createRefund` has no idempotency key. A timeout after Paystack accepted leaves the row `refund_pending`; the next `submitPending` or an operator retry asks again. Not verifiable without Paystack's sandbox | List the transaction's refunds before re-asking; record Paystack's refund id from the first reply | Open, unverified | S |
| R-10 | Medium | Order lifecycle | No returns, no post-delivery refund, no dispute: refunds exist only for a line cancelled before it ships | Return request model, reason codes, seller response, refund of a delivered line | Open | L |
| R-11 | Medium | Observability | Nothing alerts on `needs_review` payments, failed refunds or an expiry sweep that did not run; there is no scheduled reconcile of `pending` attempts the buyer abandoned (only expiry verifies them) | Daily `payments:reconcile`, operator notification on new `needs_review`/failed refund, heartbeat for the scheduler | Open | M |
| R-12 | Medium | Data retention | Even with F-04, a deleted buyer's finished orders cascade-delete (`orders.user_id ON DELETE CASCADE`), taking seller revenue history and tax records with them. POPIA vs financial-records retention is the owner's call | Anonymise the user, keep orders (5-year retention); nullable `user_id` | Open, owner decision | M |
| R-13 | Medium | Media / deploy | Photos are one 1600 px rendition (no thumbnails; a 12-card page loads 12 full images), no `loading=lazy`, no cache headers for `/storage`. The deploy does not run `storage:link` or create the docroot `storage` symlink (`docs/deployment.md` says so): if nobody did it by hand, every listing photo is a 404 | Thumbnails (480 px) at upload, `Cache-Control: public, max-age=31536000, immutable` (names are random), an idempotent `[ -e public/storage ] \|\| artisan storage:link` deploy step | Open, host unverified | M |
| R-14 | Medium | Dependencies | `composer audit`: 8 advisories in symfony http-foundation, http-kernel, mime (medium, header injection), routing, yaml. `npm audit`: 9, all dev tooling (vite, concurrently, shell-quote). Upgrading dependencies needs approval (Boost guideline) | `composer update symfony/*` then full suite on both databases; `npm audit fix` | Open | S |
| R-15 | Medium | Search | Name-only `ILIKE '%x%'`, no sort, no price filter, ordered by `created_at` without a tiebreak (page 2 can repeat a row). Fine at 5k products; scans at 500k | `pg_trgm` GIN index, tsvector over name+description, sort/price/store filters, `latest('id')` tiebreak | Open | M |
| R-16 | Low | Authorisation | `/orders/{id}` answers 403 for someone else's order and 404 for none: ids are enumerable (sequential `#00001`) | 404 for both; public order numbers | Open | S |
| R-17 | Low | Abuse | No throttle on cart add/update, review post, promo create; login is limited (5/min). Promo guessing is bounded (10/min per user and IP) | `throttle:60,1` on cart/review/promo writes | Open | S |
| R-18 | Low | Inventory | F-01's session approach fails open (typed number used) after the session expires; a hidden `stock_was` field is exact | Add `<input type="hidden" name="stock_was" value="{{ $product->stock }}">` and prefer it in `stockToStore()` (UX branch owns the view) | Open | S |
| R-19 | Low | Security headers | CSP still `Report-Only` with `unsafe-inline` and `unsafe-eval` | Enforce after reviewing `csp.violation` logs; replace the Tailwind CDN with the built CSS | Open | M |
| R-20 | Low | Notifications | Buyers get no message when payment is received, a refund is submitted or processed; sellers get no stock-out warning (`LOW_STOCK_BELOW` is only a dashboard count); in-app notifications only, no email, no preferences | Mail channel + preferences table | Open | M |
| R-21 | Low | Locking | `applyCharge` locks payment then order; `cancelUnpaidOrder` locks order then updates payments: a textbook lock-order inversion. Not reproduced (4 rounds x 10 racers, 0 crashes); PostgreSQL would abort one side and the webhook retry / next sweep heals it | Take the order lock first in both | Open, not reproduced | S |
| R-22 | Low | Docs | README says a shared `infodot` database; `EcosystemAuthController` says each app has its own; `.env.example` has `DB_DATABASE=infodot`, `BROADCAST_CONNECTION=reverb` (nothing broadcasts), `APP_URL=http://...` | One statement per environment | Open | S |
| R-23 | Info | Static analysis | PHPStan 99 errors (mostly missing generics on relations), not in CI | Larastan baseline then CI gate | Open | M |
| R-24 | Info | Stack | Livewire 3.8 (4.4 exists), 8 composer packages one minor behind | Planned upgrade | Open | S |

## 3. What is solid and must be kept

- **Checkout transaction** (`CheckoutService`): products locked in id order (no deadlocks between buyers sharing products), stock re-checked and decremented with `WHERE stock >= qty` (protects even where row locks are no-ops), promo row locked and counted in the same transaction, line snapshots (name, store, seller, unit price), `OrderPlaced` dispatched after commit. Proven again by `ParallelCheckoutPostgresTest`.
- **Idempotency**: single-use UUID per form render, unique `(user_id, idempotency_key)`, replay returns the same order; the cart is empty on replay and that is handled first.
- **Pricing**: nothing priced from the request; integer cents; `Money::allocate` largest-remainder so line shares sum to the discount to the cent; one `OrderPricing::apply` used by checkout and cancellation; order carries its own currency; promo rules snapshotted on the order, so editing or deleting the promo never changes an order.
- **Payments**: `hash_equals` of HMAC-SHA512 over the raw body before parsing; callback never believes the query string (asks Paystack, and only against this order's own attempts); amount, currency and reference matched against the attempt; unique indexes on `reference` and `(provider, provider_transaction_id)`; one function (`recordSuccessfulCharge`) is the only way an order becomes paid; whitelisted `PaymentEvent` summary, no card data, no key in logs; live key refused without `PAYSTACK_ALLOW_LIVE`; authorisation URL accepted only on a paystack host over https; redirects not followed, no retries.
- **Refunds**: owed amount is written inside the cancellation transaction, the Paystack call happens after commit and never undoes a cancellation; "refunded" only when Paystack says processed or an operator attests.
- **Lifecycle**: one choke point (`OrderLifecycle`), per-line status, order status derived from lines, cancel is idempotent (no double restock or promo release), cancel/ship serialised on the order lock.
- **Tenancy**: `OrderAccess` gives a seller only their own lines, net total, and the buyer's address only while one of their lines still has to ship; `scopeBindings()` on image routes; `HasUserScope` on carts; policies keyed on the snapshot `seller_id` so they survive deleted products.
- **Uploads**: type by content, decoded and re-encoded by GD (EXIF/GPS and appended payloads gone: `test_a_jpeg_with_php_appended_is_stored_without_the_php...`), size and pixel caps, random names on the public disk.
- **Route-cache safety**: the `operator` alias is in `bootstrap/app.php`; `checkout` and `webhooks` limiters are in `AppServiceProvider::boot`; `routes/*.php` declares routes only (now enforced by `RouteCacheSafetyTest`). SSO sign-in followed by cookie-only calls works with `route:cache`, `config:cache`, `event:cache` and `APP_ENV=production`; the Sanctum stateful-host failure seen on Press and Pulse cannot happen here because nothing uses `statefulApi()` and `auth:sanctum` falls back to the `web` guard (`sanctum.guard => ['web']`).
- **SSO door**: Origin must be InfoDot's exact host, token read from the POST body only, redirect allow-list rejects `//`, `..`, backslashes and control characters.
- **Session cookie** is `Secure`, `HttpOnly`, `SameSite=Lax` over HTTPS; HSTS 180 days; deploy forces `APP_DEBUG=false`.

## 4. Product decisions needed from the owner

1. Go-live gate (R-02): refuse checkout in production while Paystack is not configured? Recommended yes.
2. Seller money (R-01): who holds the money and when sellers are paid; commission model; KYC. This is the biggest gap between this and a marketplace.
3. VAT and shipping (R-03): are prices VAT-inclusive; flat, per-store or carrier-calculated shipping; who issues the tax invoice.
4. Stock holding (R-05/R-06): window length, cap on unpaid orders per buyer, extend while an attempt is open.
5. "Delivered" (R-08): seller click, carrier proof, or buyer confirmation, and when revenue becomes final and reviews unlock.
6. Returns and refund rules (R-10): window, who pays return shipping, restocking.
7. Account deletion (R-12): anonymise and keep financial records, or delete after a retention period.
8. Is `emall.infodot.app` a second hostname? If so set `TRUSTED_HOSTS=emall.infodot.co.za,emall.infodot.app` before deploying F-06 or that host will answer 400.

## 5. Data model gap list for the roadmap

Exists today: users/teams, stores (one per owner), store_categories (parent column unused), products (one price, one stock, status, soft delete, rating aggregate), product_images (max 6), cart_items, orders (single free-text address string, promo snapshot, payment status, currency), order_items (snapshot, per-line status), reviews (one per buyer/product), promo_codes (store-scoped), payments, payment_events, refunds, database notifications.

Missing for a real multi-seller marketplace, in the order I would build them:

1. **Seller ledger and payouts**: `ledger_entries`, `payout_batches`, `commission_rules`, `seller_kyc`, bank details, platform fee on `order_items`.
2. **Product variants and options** (size, colour), per-variant SKU, price and stock; stock movements table (reason, actor, before/after) for audit; low-stock alerts; backorder flag.
3. **Addresses**: address book, structured fields (country, province, postal code), delivery instructions, billing vs shipping, validated per country.
4. **Shipping**: `shipping_profiles`/`rates`, `shipments` (carrier, tracking number, tracking URL, shipped_at, delivered_at, estimated_delivery, label), per-line shipment link, delivery estimates on the product page.
5. **Tax and invoicing**: tax rates, line tax, `invoices` with numbers, credit notes on refund.
6. **Returns**: `return_requests`, reasons, evidence, seller response, refund link, restock decision.
7. **Promotions**: platform-wide codes, per-user and per-order caps, minimum spend, product/category scoping, free shipping, first-order, auto-apply, usage log (`promo_redemptions` instead of a counter).
8. **Discovery**: collections, wishlist/saved items, saved searches, recently viewed, price history and price-drop alerts, product Q&A, seller ratings and response time, store pages with banner/policies, SEO slugs in URLs, full-text search.
9. **Trust and safety**: review moderation and reports, seller verification badge, listing reports, operator audit log of who approved/rejected what and why (rejection reason exists, history does not).
10. **Notifications**: mail and push channels, per-event preferences, buyer notices (paid, shipped, refunded), digest for sellers.
11. **Store staff**: roles for a store (Jetstream teams are not consulted by any commerce policy today; a team member of a seller cannot manage the store, which is safe but incomplete).
12. **Ops**: scheduled reconcile, dead-letter view for failed jobs, feature flags for payments, an admin impersonation log.

## 6. Experiments and measurements

- **Parallel processes on PostgreSQL 16** (`tests/Feature/Audit/ParallelCheckoutPostgresTest.php`, child `tests/Support/parallel-worker.php`; skips on SQLite): 5 tests, all hold (section 1).
- **Full suite on PostgreSQL** (`phpunit.pgsql.xml`, new CI job `tests-postgres`): found F-02, F-03, F-09, F-12; a PG-only class of failures the project had never run.
- **Authorisation matrix** (5 actors x 12 requests): buyer-only, seller-own-line-only, operator-only routes all correct; no IDOR; 403/404 inconsistency noted (R-16).
- **Host header**: reproduced the poisoned reset link; fixed (F-06).
- **Signed-in smoke with caches** (`tests/Support/signed-in-smoke.sh`, new CI job `signed-in-smoke`): 34 pages for guest, buyer, newcomer, seller, operator, an SSO sign-in followed by three cookie-only requests, host pinning, both limiters, unsigned webhook. Mutation check: moving the `operator` alias into `routes/web.php` fails it with "Target class [operator] does not exist" while the in-suite tests still pass.
- **Synthetic dataset** (PostgreSQL, 2,000 users, 300 stores, 5,000 products, 80,000 orders, 200,000 lines, 20,000 reviews, 10,000 photos), warm, ms per request including the framework:

| Page | Queries | Before | After |
|---|---|---|---|
| `/products` (12 cards, filter counts) | 6 | 74 (cold) / 26 | 15-54 |
| `/products?q=phone` | 6 | 14 (and no results: F-02) | 16 |
| `/products/{id}` | 7 | 6 | 6 |
| `/cart` | 8 | 25 | 14 |
| `/orders` | 7 | 11 | 9 |
| buyer dashboard | 14 | 21 | 24 |
| seller dashboard (typical, 667 lines) | 18-19 | 40 | 28 |
| seller dashboard (50k delivered lines) | 19 | **1,425** | **131** |
| `/sell/orders` page 1 and page 300 | 8 | 27 / 50 | 49 / 50 |
| operator products, payments | 8-10 | 11-12 | 11-12 |

Query counts stayed constant as rows grew (no N+1 found). `EXPLAIN`: the name search is a sequential scan (1.6 ms at 5k rows; linear), see R-15. Photo weight and `/storage` caching were not measured with real photos.

## 7. Remaining risk and what I could not verify

- **The production host**: whether `storage:link` and the docroot `storage` symlink exist (R-13), the real `APP_URL`/aliases (F-06 lockout risk if wrong), whether cron and the queue worker line were installed, what is in the host `.env`. The deploy workflow and `docs/deployment.md` were read, not run.
- **Paystack live behaviour**: refund idempotency (R-09), webhook source IPs, partial-refund rules, settlement timing. Everything was tested with faked HTTP.
- **Real photo sizes and CDN behaviour** (R-13), real CSP report volume (R-19).
- **Lock-order inversion** (R-21) was probed, not proven.
- **Laravel Boost**: the MCP server answers over stdio (10 tools). I did not use `database-query` or `database-schema` because the project `.env` points at the shared local `infodot` PostgreSQL database; the schema was read from migrations and from a throwaway database instead.
- **A side effect to know about**: the first PostgreSQL run I made with `phpunit.pgsql.xml` forgot the throwaway-database overrides and connected to the local `dot_emall_test` database (user `postgres`, the file's default, the same default CI uses). `RefreshDatabase` rebuilt that database's schema. It is a test database by name and held no application data I could see, but if the UX branch kept anything in it, rebuild it. Everything after used `emall_be0_audit` and `emall_be0_perf`, both dropped at the end.
- Not audited in depth: Dot.Analytics client/collector (tests are extensive and assert no row-level data leaves), Jetstream team screens, email templates.

## 8. How to run what was added

```sh
# PostgreSQL parity (throwaway database; the suite migrates it)
createdb -h /tmp emall_pg
DB_HOST=/tmp DB_USERNAME=$USER DB_PASSWORD= DB_DATABASE=emall_pg vendor/bin/phpunit -c phpunit.pgsql.xml

# the production boot, signed in
sh tests/Support/signed-in-smoke.sh
```

New in this branch: `phpunit.pgsql.xml`, CI jobs `tests-postgres` and `signed-in-smoke`, `tests/Feature/Audit/` (BackendAuditTest, ParallelCheckoutPostgresTest, RouteCacheSafetyTest, SignedInSmokeTest), `tests/Support/` (parallel worker, SignedInSmoke, smoke runner), `App\Models\PersonalAccessToken`, `App\Http\Middleware\TrustedHostList`, migration `2026_11_08_100000_add_foreign_key_indexes`.
