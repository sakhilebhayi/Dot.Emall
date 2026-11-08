# R-43 QA: security and performance (secperf)

Branch `feat/r43-secperf`, cut from `feat/r43-emall`. Decisions D-160 to D-169 in `R43_DECISIONS.md`. Done 2026-11-08 by reading the code, probing a running copy (SQLite, port 9103) with HTTP requests as each kind of person, and measuring on PostgreSQL 16 with a bulk-seeded throwaway database.

## What was tested

### Security

| Area | How | Result |
|---|---|---|
| Authorisation on every route | Route list read for any non-GET without auth; then a matrix of 85 requests (buyer, seller, operator, order, return, address, saved search, alert, wishlist, review, question, parcel, shipment, return photo, product, image, promo, shipping, operator pages) run as a stranger buyer, a seller of another store, a signed-in person with no store, and a guest. Positive controls run as the owner. | Every foreign request answered 401/403/404 (or sent a person with no store to "open a store"). One inconsistency kept on purpose: `/orders/{id}` answers 403 where newer routes answer 404 (D-169). |
| Mass assignment | Every `$fillable`/`$guarded` read; every `create`/`update` from request data read; a store-opening request sent with `status`, `is_active`, `owner_id`, `reviewed_by`, `team_id`. | All controllers pass a validated whitelist or `request->only(rules)`. Store, product, address, review, promo cannot be set to a status or owner by the request. Passed. |
| CSRF | Every POST/DELETE without a token against 12 endpoints; the exempt routes read. | 419 everywhere except the three exempt ones: `/webhooks/paystack` (HMAC), `/csp-report` (logs only a directive and a host), `/auth/ecosystem` (Origin/Referer check, token in the body). Passed. |
| Stored and reflected XSS | `"><svg onload=alert(1)>` plus `</script><script>` planted in product name/description/attributes/options, store name/description/policies/city, review, question, answer, review reply, collection title/description, order messages, delivery name/address/phone, guest e-mail, address book, return notes, seller notes, shipment carrier/tracking/events, report details, saved search names, order line names, user name; then 50 pages fetched as buyer, seller, operator and guest, and the search query echo, JSON-LD, meta tags and `<title>`. | No unescaped payload on any page. JSON-LD is hex-escaped; the `{!! $heading !!}` in the search view is escaped by the layout. Passed. |
| File uploads | Code read for listing photos, store logo/banner, review photos, return photos. A 1.1 MB solid-colour PNG that describes 20,000 x 20,000 pixels was built and decoded. | **Defect:** review and return photos were decoded before their pixel size was checked: the 1.1 MB file took 1.8 GB resident (GD's allocator is outside `memory_limit`). Fixed (D-166). Listing photos were already safe (content sniffing, GD decode and re-encode, EXIF stripped, random name). Review and return photos live on the private disk and are streamed only after an ownership/visibility check (tested in the matrix). |
| Private streaming authorisation | Return photo route and seller return photo route in the matrix; review photo controller read. | Passed. |
| SSRF and open redirect | Every `redirect(` and `Http::` read. | No outbound request takes a user-supplied URL. The only user-influenced redirects (`return`, intended URL, `redirect` after SSO) accept a same-site path only. Paystack's `authorization_url` is accepted only for a Paystack checkout host. Tracking links come from a carrier allowlist. Passed. |
| Rate limits | Limiters and `throttle:` middleware read for login, guest order access, checkout, search suggest, assistant, community writes, store messages, reports, order messages (daily cap in the service), promo guesses. | Present everywhere that matters except `/search`, `/categories`, `/products` (see open items). |
| Paystack webhook | Controller, handler and `PaymentService::applyCharge` read; the 19-test webhook file read. | Raw-body HMAC-SHA512 with `hash_equals` before parsing, one generic 401, replay answered `duplicate`, amount and currency mismatch go to review, one Paystack transaction per payment (unique index), cancelled/paid/underpaid orders go to review, live key refused unless allowed. Passed. |
| Session, cookies, headers | A request over HTTPS built in-process; headers read. | Session and XSRF cookies `Secure` over HTTPS, session `HttpOnly`, `SameSite=lax`; HSTS 180 days; nosniff, frame options, referrer policy, permissions policy; nonce CSP enforced (not report-only) by default. Passed. |
| Secrets in the repository | `git log -p --all` searched for `sk_live`, `sk_test`, AWS keys, private keys, `APP_KEY=base64`; tracked files searched for `.env`, `.pem`, `.sqlite`. | None (only a fake `sk_test_demoseeddata0000` in the demo seeder and an all-A `APP_KEY` in a CI file). Passed. |
| Dependency audit | `composer audit --locked`, `npm audit`. | `composer.lock` is clean (symfony 8.1.11, the version the advisories fixed); the worktree `vendor/` is a stale copy, which is why a plain `composer audit` here lists eight advisories. `npm audit`: two moderate findings in the dev-only build chain (`@tailwindcss/typography` via `postcss-selector-parser`, a build-time CPU issue); the fix is a major bump of the typography plugin and was not applied. |
| Logs | Every `Log::` call read. | Only ids, endpoint names and exception class names are logged; no e-mail, token, address or message body. The CSP report logs a sanitised directive and host. Passed. |
| POPIA | Export, deletion, consent, retention read. | Export download exists; closing an account anonymises and keeps the sale records (D-135, D-137 owner items stand); marketing is off in every channel by default; search history 90 days, views 90 days, events 400 days, scheduled daily. Not tested: that the export is complete for every table (not audited line by line). |
| Guest magic links | Token code read and tested. | 256-bit HMAC of order, e-mail and version under the app key; only its SHA-256 is stored; 180 days; the link redirects at once to a URL without the token; not logged by the app; throttled per IP and per order. **Defect:** rotating the link did not end sessions that had already used it. Fixed (D-165). Accepted: the token is in the web server's access log for the request that follows the link (inherent to a magic link), and rotating `APP_KEY` invalidates every guest link. |
| E-mail content | Notification layout read, then rendered with hostile text. | **Defect:** a buyer's message to a seller or to the Emall team was rendered as Markdown inside a Dot.Emall e-mail, so `[text](https://evil)` became a real link and `![](url)` an image. Fixed (D-168). |
| Public input amplification | `/search` sent 300 attribute filters, 2,000 brands, 2,000 stores, a 5,000-character query, page 99999, `per_page=100000`, SQL-ish text. | **Defect:** 300 attribute filters took 9.0 s and returned 2.6 MB. Fixed (D-167): 33 ms and 83 KB. The rest were already bounded. |
| CSV export | Code read. | Formula characters are prefixed (D-108). |
| Dev sign-in route | Route and deploy file read. | Registered only when the environment is `local` or `testing` at route-cache time; the deploy forces `APP_ENV=production` and `APP_DEBUG=false`. Passed. |

### Performance

Method: PostgreSQL 16, throwaway database (dropped at the end), the demo seed plus a one-off SQL clone (not committed) to 60,120 listings (120,240 photos, 119,739 variants), 154 stores, 32,032 orders, 61,061 order lines, 37,037 parcels, 150 reviews and 60 questions on one product; search index rebuilt (60,120 documents, GIN index), `ANALYZE`d. A script ran each page in-process through the HTTP kernel with the query log on (second of three passes, warm file cache), as a guest, a buyer with 7,007 orders, a seller with 6,000 parcels and an operator. Slow queries were then run under `EXPLAIN (ANALYZE)`. "Before" is the unmodified trunk (`d4cc857`) on the same database with the four new indexes dropped; "after" is this branch. Timing noise between runs is about plus or minus 15 percent.

| Page (60k listings) | queries before | queries after | total ms before | total ms after |
|---|---|---|---|---|
| home | 59 | 38 | 315 | 112 |
| operator home | 19 | 19 | 4,181 | 165 |
| operator flagged listings | 6 | 6 | 4,319 | 289 |
| seller dashboard | 29 | 29 | 164 | 106 |
| seller orders | 11 | 11 | 106 | 70 |
| seller parcel | 13 | 13 | 17 | 11 |
| operator health | 11 | 11 | 63 | 50 |
| search (text, facets) | 20 | 20 | 128 | 128 |
| search (filters, price sort) | 20 | 20 | 198 | 189 |
| category | 21 | 21 | 174 | 173 |
| product page (150 reviews, 60 questions) | 51 | 51 | 64 | 74 (noise) |
| store page | 25 | 25 | 46 | 43 |
| cart, checkout, account orders (7,007 orders), account home, order page | 7 to 23 | same | 15 to 26 | same |
| seller products, ledger, returns, analytics; operator queues | 1 to 24 | same | 6 to 134 | same |

N+1 check: every page costs the same number of queries with 2 or thousands of rows behind it (account orders: 7; product page: 45 to 51 with 150 reviews against 46 with 4). The only per-row query found was the stores' rating on the home page (4 queries, now 1).

What changed (all D-160 to D-164):

* `operator home` and `flags` 4.2 s to 0.17 s and 0.29 s: the price rule rebuilt the category's price list for every candidate listing; now one sorted list per category (output identical, tested against the original algorithm).
* Home 59 to 38 queries: two dynamic collections ran full searches including a dozen facet queries each; they now ask for products only.
* Missing indexes found by `EXPLAIN`: `order_items (order_group_id)` had none (a parcel's items: 6 ms to 0.03 ms; the seller's parcel list with its item check: 14 ms to under 1 ms; the queue counts for a store with 6,000 parcels stay near 12 to 17 ms because they count thousands of rows), `order_groups` was indexed by seller but queried by store, `orders (created_at)`, `search_documents (indexed_at)` (14.6 ms to 0.1 ms). The PostgreSQL index test lists the new query shapes.
* The search text path uses the GIN index (`search_documents_vector_gin`, bitmap index scan) for single and multi-word and prefix queries; the trigram index serves title matches. Checked in the plans. Each search still reads the `products` table (about 3 ms) to apply visibility; a covering partial index was measured (10 ms to 3 ms per facet query) and not added because it would turn every stock change into a non-HOT update. Facet counts are already cached 60 s in production.
* Page weight: stylesheet 151 KB to 26.6 KB gzipped and cacheable for a year; a grid of 24 cards now loads 640 px pictures instead of up to 1,600 px ones (the demo photos are 20 KB so the saving is not measurable here; a 1,600 px JPEG at quality 85 is typically 200 to 400 KB against 40 to 90 KB at 640 px: not measured on real seller photos).
* Images in views already have width and height, lazy loading below the fold and an eager, high-priority hero: no layout shift found. Not measured in a browser.
* Scheduled commands: all nine use `withoutOverlapping` with an expiring lock (10 to 45 minutes). Checked with `schedule:list` and by reading.

## Fixed (commit, test)

| Defect | Test | Commit |
|---|---|---|
| Rotating a guest link left existing sessions in | `Security\GuestAccessTest` | see git log |
| Review and return photos decoded before size check | `Security\UploadLimitsTest` | |
| 300 search filters took 9 s | `Security\SearchInputLimitsTest` | |
| Markdown links and images in notification e-mails | `Security\MailContentTest` | |
| Price rule O(n x m); count recomputed on every operator home | `Performance\HotPathsTest::test_the_price_rule_*` | |
| Facet queries for collections and digests | `Performance\HotPathsTest::test_a_request_that_wants_only_products_*`, `test_a_dynamic_collection_*` | |
| N+1 store ratings on the home page | `Performance\HotPathsTest::test_several_stores_ratings_*` | |
| Missing indexes | `Performance\HotPathsTest::test_the_hot_path_indexes_*`; `Commerce\IndexesPostgresTest` (four new shapes) | |
| Full-size photos in card grids | `Performance\HotPathsTest::test_an_upload_also_stores_*`, `test_a_photo_that_is_already_small_*`, `test_the_backfill_command_*` | |
| No cache or compression for assets | Verified with Apache 2.4.68 (`curl -I`), no automated test | |

Tests written before the fix and seen failing: `GuestAccessTest` (rotation), `UploadLimitsTest` (both validators), `SearchInputLimitsTest` (attribute and value caps), `MailContentTest` (link, image). The performance tests pin behaviour (identical output, query counts, index existence) and would also have passed on the old code except the facet flag and the card-picture tests.

## Not fixed, and still unverified

* `/search`, `/categories` and `/products` have no rate limit. A limit low enough to matter would also throttle search-engine crawlers; left for the host or a CDN. Pages are 20 to 25 queries and 110 to 190 ms at 60,000 listings uncached.
* The shopping assistant is limited per address (8 a minute, 60 an hour) but has no global ceiling; if a model key is set, a spread-out abuser could run up a bill.
* A new store is live at once and a listing edited after approval is not re-reviewed (D-169). Product decisions for the owner.
* Expired sessions are only swept by the 2 percent lottery; each anonymous crawler request writes a session row.
* The guest link is in the web server's access log; the link grants one order (name, address, phone) for 180 days.
* The `.htaccess` speed block works under Apache 2.4.68 here; not tried on the production host (LiteSpeed/cPanel may behave differently, and a host that still serves a copied docroot does not read `public/.htaccess`).
* `emall:images-thumbnails` has to be run once after deploy for existing photos. It was run for 20 photos and the whole path is covered by tests, not run over a full catalogue.
* Not tested: the real Paystack hosted page, the production mail transport, CSP in a real browser after my view edits (only `cardUrl()` replaced `url()` in image attributes), Livewire's own endpoints beyond the notification bell, the POPIA export's completeness table by table, load under concurrent users (one request at a time was measured).
* Browser tools were not used; everything above is HTTP and database level.
* `npm audit`: two moderate dev-time findings remain (see above).

## Test counts

See the final report; the run command is `DB_CONNECTION=sqlite DB_DATABASE=:memory: php -d memory_limit=2G vendor/bin/phpunit`, and the PostgreSQL files were run with `phpunit.pgsql.xml` on a throwaway database.
