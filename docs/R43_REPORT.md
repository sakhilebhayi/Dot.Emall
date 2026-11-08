# R-43 Dot.Emall: final report

Branch `feat/r43-emall`. Evidence-based: every number below was produced by a run in this work; anything not run is listed under "Not verified".

## 1. What was done, in order

1. Audit (UX, backend, competitors, `R43_UX_AUDIT.md`, `R43_BACKEND_AUDIT.md`, `R43_RESEARCH.md`) then plan (`R43_PLAN.md`) and decisions (`R43_DECISIONS.md`, D-1 to about D-199).
2. Design system "Shelf Edge" (`DESIGN.md`): the shelf-edge price label (price, perforation, delivery, total) is the UI unit; the order timeline is a waybill; motion verb "stamp"; palette from the owner's logo (indigo and yellow); Schibsted Grotesk and Martian Mono, self-hosted; 50 `ds` components; layouts for store, account, seller, operator, auth and errors.
3. Services and data (two data agents, then discovery, checkout, seller, returns, operator, community agents W3 to W7), integration and reconciliation (int2).
4. Buyer and seller/operator end-to-end QA, security and performance pass, an outside design jury, three polish agents, a second jury.

## 2. Status by area

Legend: **Working** (existed and verified), **Improved**, **New**, **Partial**, **Not implemented**.

| Area | Status | Notes |
|---|---|---|
| Catalogue, categories, variants, specs | New / Improved | Variants with per-variant stock and stock movements; ~130 categories with spec definitions (`emall:seed-categories`). |
| Search | New | `SearchEngine` interface; PostgreSQL tsvector + GIN (confirmed used by EXPLAIN) and a portable fallback; synonyms, typo correction, facets, suggest, search log. Input caps (6 attribute filters, 12 values). |
| Product page | Improved | Price card prints price, delivery, total and VAT; "was" price only from real price history (30-day lowest); who ships; disclosure "How this price is worked out"; no fake urgency. |
| Recommendations, home | New | Only from real data, minimum counts, basis printed; homepage built from `Homepage::for()`. |
| Shopping assistant | New | Rules-based, optional validated model; says "the catalogue holds no record of..." instead of inventing. |
| Cart and checkout | Improved | Guest checkout with signed magic links; server-side prices and recomputed totals; delivery per parcel; 30-minute stock hold; idempotent payment; payment never marked paid from the browser. Checkout refuses to run in production without Paystack configured. |
| Payments | Improved | Paystack with signed webhook: bad signature 401, replay is idempotent (one payment, one stock decrement), amount/currency mismatch flagged `needs_review`, late payment on a cancelled order flagged for review. |
| Orders, tracking, returns, refunds | Improved / New | State machine with status history; shipment events with seller-provided labels and an allow-listed carrier link (never invented tracking or estimates); returns within 7 days of delivery with photos, seller decline needs a reason, 10-day escalation; refunds via Paystack with operator retry. |
| Seller marketplace | New | Onboarding, store profile and policies, shipping methods, listings with variants and photos, review queue, orders desk, returns, internal ledger (earnings held 7 days after delivery then released; **no automated payouts**), promo codes, analytics that print "No data yet" rather than zeros. |
| Trust | New | Seller facts split into "Checked by InfoDot" and "Claimed by the seller"; no "verified" badge unless InfoDot data says so; reviews marked verified-purchase only when real. |
| Personalisation | New | Wishlist (shareable), price/stock alerts, saved searches, notification bell and preferences. |
| Operator desk | New | Listing and store review, flags, reports/moderation, returns escalations, payments desk, ledger, metrics (+CSV), merchandising, people search with access log, audit log. |
| Privacy | Improved | Data export; account closing anonymises the person and keeps financial records (D-98); closing is refused with the list of reasons while orders, returns, refunds or earnings are open. |
| Ecosystem | Partial | InfoDot sign-in and team hand-off is real. Analytics, Notify, Files, Pulse, Agents, Brain, Memory, Engage, Billing are **not** integrated: no real API contract was available (see D-50 to D-62). |
| Registration | Unchanged | Public sign-up stays closed; accounts come from InfoDot. |

## 3. Test results (this branch, final merged state)

- SQLite: 1,660 tests, 1,618 passed, 42 skipped (the PostgreSQL-only tests), 0 failed.
- PostgreSQL 16 (throwaway database, clean environment, `phpunit.pgsql.xml`): 1,660 tests, 0 failed, 7 skipped.
- Signed-in smoke with route, config and event caches on: 153 pages, 0 failed, 0 unlisted authenticated routes.
- Pint: whole repo clean. Local copy of the deploy workflow's manifest check: ok.
- Note for the next person: exporting `EMALL_E2E_FAKE_PAYSTACK`, `CACHE_STORE` or `SESSION_DRIVER` into the shell before the PostgreSQL run makes it fail (91 failures seen once); run it from a clean environment.

## 4. Defects found by QA and fixed (each has a regression test)

Most serious:
- **High:** a signed-in person could type a stranger's e-mail into their profile and stay "verified"; at next sign-in the stranger's guest orders (name, address, phone, items) joined their account. A changed address now clears verification (D-150).
- Guest link rotation did not end sessions that had already used the old link (D-160s).
- Review and return photos were decoded before their pixel size was checked: a 1.1 MB PNG describing 20,000 x 20,000 pixels used 1.8 GB. Header is now read first and the size capped.
- `/search` took 9 s and returned 2.6 MB for 300 attribute filters; now 33 ms and 83 KB with caps.
- A buyer's message became Markdown (real links/images) inside e-mails to sellers; now escaped.
- Real PostgreSQL deadlock between a stock edit and checkout (lock order); fixed.
- Session cookie had no Secure flag; now follows an https APP_URL.
- Cancelling a parcel line with an id outside the parcel said "put back in stock"; operator refund retry and mark-done wrote audit lines for calls never made; `checkout_started` was never recorded.
- Many wording and rendering faults (product spec rows without units, assistant answering "flying car for my dog" with 11 products, paid panel saying "the seller will pack it" on delivered orders, 405/413 default framework pages, profile page promising permanent deletion).

Performance (PostgreSQL, 60,120 listings, 32,032 orders): operator home 4,181 ms to 165 ms, operator flagged listings 4,319 ms to 289 ms, home 315 ms to 112 ms (59 to 38 queries); four missing indexes added (migration `2026_11_12_000001`); text and gzip caching in `public/.htaccess`; 640 px card copies for new photos.

Tenant isolation: 66 seller/buyer id-taking route-methods x 6 identities (396 requests) and 67 operator route-methods x 4 identities: nothing mutated, nothing returned 200 to a non-owner.

## 5. Design review

- Jury 1 (outside, screenshots only): Design 6.5, Usability 7, Creativity 6, Content 7, Trust/honesty 8, Mobile 7; weighted 6.6. Its main finding was that the demo product pictures did not depict the products; also checkout gating, hover feedback, wordmark legibility, mobile header weight.
- Fixes: ~95 silhouettes so each demo product is drawn as what it is; an explicit "Seller has not added a photo" state; checkout with a saved address and delivery option leaves Pay enabled; hover lift and arrow nudge; mobile header collapse; 404/403/419/429 inside the real layout; store page puts products first; home cut by three sections; order timeline with "Not recorded" and a status sentence; and more (`R43_POLISH_*.md`).
- Jury 2 (fresh juror, same method): see section 8.
- Design skills actually used: `impeccable` and `design-taste-frontend` by the chrome and pages polish agents (their draw/inspect scripts were not run because the brief fixed palette, faces and concept); the imagery polish agent invoked `impeccable` only after its first pass and never invoked `design-taste-frontend`. `emil-design-eng` is an empty directory in `~/.claude/skills` and could not be used. `hallmark`, `awwwards`, `gsap-*` and the other listed skills were not used for this build.

## 6. Owner decisions and steps (nothing here was done on the host)

1. **Wordmark:** the header wordmark is now live text (heavier weight, yellow full stop) because the thin image read as "email". Revert: `resources/views/components/ds/logo.blade.php` plus the `.ds-logo__*` rules in `resources/css/shell.css`.
2. **Host PHP limits:** the app promises 4 MB per photo and six photos; PHP defaults (`upload_max_filesize` 2M, `post_max_size` 8M) cut files off. Needs `upload_max_filesize >= 4M`, `post_max_size >= 26M`.
3. **Store go-live:** a new store is live at once without shipping methods or policies unless `EMALL_SELLER_REQUIRES_REVIEW=true` (D-159). Editing an approved listing does not trigger re-review (D-158).
4. **"Trending: N views and 0 sold"** is printed under the home hero when a 0-sales product is the pick; decide whether a product with no sales may be featured.
5. **403 vs 404:** another buyer's order id answers a designed 403 (existence is visible); newer routes answer 404.
6. After deploy: `php artisan emall:seed-categories`, `php artisan emall:images-thumbnails` (existing photos fall back to full size until then), `php artisan storage:link` if product photos are not served, and set `TRUSTED_HOSTS` if another host (for example emall.infodot.app) also serves the app.
7. Carrier tracking URL templates need owner verification before being relied on.
8. Real Paystack keys and a production mail transport are required for checkout and e-mail; checkout is refused in production without Paystack configuration.
9. Seller payouts are manual (internal ledger only); the team must pay out released earnings, and a seller with unpaid released earnings can close their account only after the team pays them.
10. Legal: ECTA return-window language is adopted as the platform standard, not a legal claim; privacy policy and terms need legal review.

## 7. Not implemented, partial, and not verified

Not implemented / partial:
- Integrations with Analytics, Notify, Files, Pulse, Agents, Brain, Memory, Engage and Billing (no real API contract).
- Automated seller payouts.
- Suggest box has no typo tolerance (full search does).
- The search fallback that matches any of your words lists loosely related products for nonsense queries (it says so on the page).
- An order shows "Refunded" while the refund is only requested from Paystack (text explains).
- A shared wishlist can list an out-of-stock product (D-90 says buyable only).
- `/search`, `/categories`, `/products` have no rate limit (would also throttle crawlers); the assistant has no global ceiling (matters only with a model key).
- CSP is report-only.
- `npm audit`: 2 moderate findings in the dev-only build chain (`@tailwindcss/typography`); fix is a major bump.

Not verified (no run was made):
- Anything on the production host: `.htaccess` gzip/caching block, HSTS over TLS, real Paystack hosted page, real e-mail delivery, real carrier links.
- Concurrent-user load; row-lock behaviour under load (relied on the suite's PostgreSQL parallel tests).
- Back-in-stock and price-drop alerts firing end to end; digest e-mails; multi-variant checkout and hold expiry on variants.
- Checkout live re-price on changing address/option in a browser; PostgreSQL search ranking after the "filter words still rank" change.
- Dark mode and screen readers beyond the jury's visual pass; prefers-reduced-motion in a browser; Safari and Firefox; tablet widths.
- Signed-in pages under production caches by a person (covered by the cached-route smoke only).

## 8. Second jury

A fresh outside juror (screenshots only, no source or earlier report) scored: Design 7.0, Usability 7.5, Creativity 6.5, Content 7.0, Trust/honesty 8.5, Mobile 7.5; weighted **7.05** (jury 1: 6.6). Reading of the identity: a receipt-first South African marketplace where every price is a "ticket"; distinct from Amazon and Takealot in behaviour and voice, less so in looks. Strongest: trust/honesty, seller and operator desks, order timeline. It could not run Lighthouse or axe, so accessibility and performance are only partly judged.

Fixed after jury 2 (round 2, `R43_POLISH_round2.md`): team entries removed from account menus (Jetstream routes and SSO team sync untouched); search no longer apologises for an exact match and hides "Clear filters" when nothing is applied; store page 2-up on phones (10,713 px to 6,123 px); sticky total and checkout bar on the phone cart; empty states carry their own glyph; first-viewport images eager; home blocks share the row.

Remaining from jury 2 (not fixed): #1 the demo product art is one illustration kit with the same backdrop on many items (demo-seed art only; real sellers upload photos); #9 hero ticket "Not known yet" dead end; #10 CTA arrow nudge/press state; #12 narrow review column; #13 order-line thumbnails; #14 category intro length; #15 checkout stepper does not follow the page. Round 2 looked only at dark mode at 375 and 1440 for most changes; light mode and 390 width were not re-checked, the new sticky cart bar has no dedicated test, and keyboard focus on it was not verified. Round 2 invoked `impeccable` only.

## 9. Deployment

Merged as PR #33 (squash, `15585d0`) and deployed on 2026-11-08 (workflow run 42506577887, green: manifest check, frontend build, code deploy, cron lines, live smoke, folder probe).
- Migrations: 70 ran on the host, including `2026_11_11_000001` (RESTRICT user keys on money records) and `2026_11_12_000001/000002` (hot-path indexes, thumbnail column).
- Live checks afterwards (curl): `/`, `/products`, `/search?q=kettle`, `/stores`, `/up`, `/login`, `/cookies` answer 200; `/orders`, `/sell`, `/operator` redirect a guest to `/login`; `/dev/login/...`, `/dev/kit` and unknown paths answer 404; `/cart` answers 200 for a guest (guest cart is by design); CSS (166 KB) and the self-hosted font answer 200; HSTS, CSP (nonce, report-only handling as configured), nosniff, frame, referrer and permissions headers present.
- **The live shop is empty**: no categories and no listings yet. Run `php artisan emall:seed-categories` on the host (the deploy does not), then sellers can list. Other owner steps are in section 6.
- Not checked live: a real InfoDot sign-in end to end, a real payment, real e-mail, the `.htaccess` gzip/caching block on the host, the scheduler running (cron lines were installed by the deploy; a first run was not observed).

CI on PR #33: GitGuardian reports 4 "generic password" findings, all the throwaway PostgreSQL service-container password in `.github/workflows/ci.yml` (a CI-only database that exists for the job's lifetime); judged a false positive, not a secret.
