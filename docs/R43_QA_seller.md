# R-43 QA: seller, operator and platform

Branch `feat/r43-qa-seller`, cut from `feat/r43-emall` at d4cc857. Run 2026-11-08 on a local SQLite file (own port), demo world from `emall:demo-seed --fresh`, `EMALL_E2E_FAKE_PAYSTACK=true` with a test key so checkout is the real gateway path (orders were placed, paid through the fake hosted page and its real callback, and the webhook was driven with a body signed by the configured secret). Decisions D-150 to D-159 are in `R43_DECISIONS.md`.

How it was driven: the built-in browser for the visual pass (seller and operator desk, 375 px and desktop, dark theme), and a scripted HTTP client with real cookies, CSRF tokens and multipart uploads for the journeys, because that is the only way to repeat the same request as five different people. Nothing was marked paid, shipped or refunded by editing a row, with these exceptions, each only to move the clock: ledger `release_at` set into the past to run `emall:release-ledger`; a promo code's `expires_at` set into the past; one account's `email_verified_at` cleared to act as an unverified person. Both were restored.

## Result in one table

| Journey | Result |
|---|---|
| 1 Seller onboarding (nomsa, brand new): store, profile, policies, shipping methods, VAT, trust wording | Passed. Two small copy defects fixed (D-155 words, see below). Trust ladder says "Signs in through InfoDot", "Company registration number", "VAT number" as checked or claimed; no "verified" anywhere unless `teams.infodot_verified_at` (only InfoDot's payload writes it). Store review path (`EMALL_SELLER_REQUIRES_REVIEW=true`: draft, submit refused while incomplete, send back with reason, resubmit, approve, public only after approval) passed. |
| 2 Listings: variants, options, specs, images, stock, review, rejection, edit after approval | Passed with defects fixed (photo messages D-155; dashboard option label D-157). Edit-after-approval is by design (D-158), re-tested. |
| 3 Orders: notice, ship, tracking, delivered, cancel, refunds, returns, ledger, analytics, promo, storefront, enquiries | Passed with two defects fixed (D-151, D-153) and one found in the operator desk (D-152). |
| 4 Operator: listing queue, store review/suspend/reinstate, reports, returns, payments desk, ledger, metrics (+CSV), merchandising, people, audit | Passed with defects fixed (D-152, D-154). |
| 5 Tenant isolation sweep | Passed: 0 leaks in 396 cross-tenant requests and 268 operator-route requests. One cross-account hole found through the profile form, not through an id: fixed (D-150). |
| 6 Platform in production mode | Passed with one fix (D-156). |

## Defects found and fixed (each has a regression test in `tests/Feature/Qa/SellerQaRegressionTest.php`)

| # | Defect | Severity | Fix (commit) | Test |
|---|---|---|---|---|
| D-150 | Anyone could type a stranger's e-mail address into the profile form and stay "verified". At the next sign-in the guest orders placed with that address (delivery name, street address, phone, items) joined the typist's account and opened on `/orders/{id}` (reproduced on the demo guest order). The same stale flag defeats InfoDot's "adopt an unverified local account" step and the operator-grant check, which both read `email_verified_at`. | High (privacy, cross-account) | `UpdateUserProfileInformation` clears `email_verified_at` when the address changes (e6811d5) | `test_a_typed_new_email_address_is_unverified_and_cannot_claim_a_strangers_guest_orders`, `test_changing_only_the_name_keeps_the_address_verified`, `test_a_case_only_change_of_the_address_stays_verified` |
| D-151 | `POST /sell/orders/{group}/cancel` with an item id that is not in the parcel (stale page, tampered form) cancelled nothing and answered "Items cancelled and put back in stock" | Medium (false confirmation to a seller about money and stock) | all ticked ids must be the parcel's, else a field error and nothing changes (ee6c8be) | `test_cancelling_with_an_id_that_is_not_in_the_parcel_cancels_nothing_and_says_so` |
| D-152 | Operator "Retry" on a refund already submitted or refunded answered "Refund request sent to Paystack" and wrote a "Retried a refund" audit line for a call that was never made; "Mark done" twice wrote a second audit line | Medium (false audit trail) | guards in `OperatorDecisions` plus the controller shows the refusal (ee6c8be) | `test_retrying_or_marking_done_a_refund_that_is_already_in_hand_is_refused_and_not_audited` |
| D-153 | `checkout_started` was never recorded (the method existed, the completion-rate metric read it, no caller). On a live shop the operator's "Checkout completion" could never have a denominator | Medium (a metric that can never be true) | `CheckoutController::show` records it for a checkout that can be paid | `test_opening_the_checkout_records_checkout_started_...` |
| D-154 | Operators page printed `&lt;span class=&quot;op-code&quot;&gt;...` as visible text | Low | plain text | `test_the_operators_page_prints_its_instructions_not_the_markup_around_them` |
| D-155 | A refused photo said "The images.0 field must be a file of type: image/jpeg, image/png, image/webp."; a file larger than the web server's upload limit (PHP default 2 MB here) said "The images.0 failed to upload." | Low-medium (seller cannot tell what to do; the page promises 4 MB) | `ProductImages::messages()` on the product form, the photo drop zone and the logo/banner form | `test_a_refused_photo_is_explained_in_words_not_as_images_dot_0` |
| D-156 | Session cookie had no Secure flag (nothing sets `SESSION_SECURE_COOKIE`; `.env.example` and the deploy notes do not mention it) | Low-medium | default follows APP_URL https | `test_the_session_cookie_is_secure_when_the_app_url_is_https_unless_the_host_says_otherwise` |
| D-157 | Dashboard low-stock line listed "Beaded tee (5 left), Beaded tee (5 left)" for two options | Low | the option is named | `test_the_low_stock_line_on_the_dashboard_tells_two_options_of_one_product_apart` |

## What was tested, journey by journey

**1 Onboarding.** `/sell/start` (public, plain terms for fees, payouts, returns, shipping, privacy); open a store as a new person (empty form refused with words; name with `&` and `<b>` kept as typed and escaped everywhere: public store page, titles, listing, checkout, operator pages, JSON-LD, CSV); the status page with its checklist; profile validation (e-mail, phone, province, VAT and registration formats, lengths); VAT toggle (shown as claimed, "VAT included" appears in the buyer's order only for a store that says it is registered: R43.70 of R335.00 checked by hand); policies shown on the public store page; shipping methods (flat, free over a threshold, collection; negative, three-decimal and huge prices, min greater than max, missing threshold, missing collection address, unknown kind, a name that is an `<img onerror>` all refused or escaped). Trust wording on the public store page: sign-in through InfoDot "Not recorded", company link "Not recorded", registration and VAT "Claimed by the seller", measures say "Not enough orders yet (needs 5; has 0)". `EMALL_SELLER_REQUIRES_REVIEW=true` run: draft is 404 to the public, submit is refused with the missing items, an operator sends it back with a reason the seller reads, resubmit, approve, public.

**2 Listings.** Create with one, three and seven photos; wrong type (PHP file named .jpg, SVG, GIF, a fake JPEG header), too small (50 px), too wide (7000 px), 25-megapixel bomb (6000x6000), 5000x5000 allowed, 2.3 MB and 3.1 MB (cut off by the 2 MB PHP limit), 17 MB (413 from PHP before the app sees it): every refusal is clean and nothing half-created; accepted photos are re-encoded. Duplicate SKU refused. Options (up to 3) and variants with their own price, stock and SKU; a combination left without a price is not sold; negative or non-numeric variant values refused with the row named. Specifications validated against the category definition (enum, required). Stock movements written with "Seller typed 5 over 0"; the form's stale `stock_was` produced "Stock is 2, not the number on the form, because units were sold while you were editing". Submit for review is automatic (pending, invisible to the public: 404 on the page, absent from search and the store page). Operator rejects (empty and 2-character reasons refused, a real one saved, listed on the seller's edit page and in a notice), the seller resubmits, the operator approves; double approve and double reject are refused. Edit after approval (D-158): the listing stays live; a "Replica ... vape" rename shows in the operator's flagged queue within the same page load. Soft delete of a listing with orders, returns, a cart line and a wishlist entry: every page that mentions it still renders ("Unlisted" in the wishlist, history intact).

**3 Orders.** New-order notice URL is the seller's parcel page (`/sell/orders/{group}`), also for the unpaid legacy order (`(unpaid)` in the text). Unpaid parcel: pack, ready, ship and deliver all refused with the reason. Deliver before shipped refused. Ship: unknown carrier key refused; an Aramex number with letters ships as plain text with no link; "other" carrier name typed as `<b>My Bakkie</b>` shows escaped to the buyer with "The seller did not give a carrier we can link to"; links come only from the allowlist and only when the number matches that carrier's pattern. Tracking update, seller-typed events (escaped, labelled "from the seller", with the "not checked with the carrier" sentence). Collection parcel: ship refused, ready, collected, collected twice refused. Cancel a line: needs a reason, restocks, writes a ledger reversal and a Paystack refund request (`refund_submitted`); a shipped line cannot be cancelled. Returns: request with and without photos; decline needs a reason (short refused), approve after decline refused, receive before approve refused, approve then receive with restock refunds R398.00 and puts two units back; escalation by the buyer and settlement by the operator (refund or uphold) with notes; escalate twice and escalate while still waiting refused. Ledger after all of it: sale R648 reversed by R250 and R398 to zero; `emall:reconcile --days=30` "Everything agrees"; release command holds back parcels with an open return. Dashboard, analytics and ledger figures re-added by hand from the orders (R1,210.00 of 4 orders and 5 items; held R1,550.00; refunded after delivery R648.00) and all agreed. A store with no orders shows "No data yet" on the dashboard, analytics, ledger, orders, returns and reviews pages (no zeros). Promo codes: percent over 100, negative, zero, spaces, HTML, past expiry, duplicate by case all refused; applied lower-case; minimum spend, expiry, total uses (exhausted after one order), per-buyer limit (second order refused), other store's code and an SQL string all give the single "not valid" sentence; 10 failed attempts a minute throttled. Store enquiry: validation, own store refused, guest sent to sign in, the notice carries the sender's name and sign-in e-mail, the text is escaped. CSV exports: a product named `=HYPERLINK(...)` is exported as `'=HYPERLINK(...)`.

**4 Operator.** Listing queue and flagged queue; stores (approve, send back, suspend, reinstate; each needs a reason of at least five characters, repeats refused, a suspended store disappears from the public page, search and directory and cannot reopen itself, and its open orders stay workable); reports on a product, store, review and answer (duplicate report refused, own item refused, a report hides nothing; remove takes a listing down, dismiss leaves it, an outcome note is required); returns escalations; payments desk (gateway state, owed refunds, in-flight refunds, unmatched events, events log); webhook with no signature, a tampered one, non-JSON, no event name, an unknown event, a wrong amount (recorded `unmatched / amount_mismatch`, order stays unpaid), the right amount (order paid once), replays (duplicate), `refund.processed` then `refund.failed` (the terminal state wins), `refund.failed` then retry and mark-done; ledger reconciliation page, health, metrics (7, 30, 90 days, CSV: "Search to product 132.1%" is explained on the page as more than one result opened per search), merchandising (collections, categories, featured stores, synonyms, complements, zero-result queries), people search (masked e-mail, each opening logged), audit trail (13 action kinds written during the run).

**5 Isolation sweep** (script `qa-seller-tools/iso_run.py`, not committed: it lives in the scratchpad). Every route in `php artisan route:list` that takes an id or slug and belongs to a seller or a buyer (66 route-methods: addresses, alerts, saved searches, returns and their photos, orders, receipts, items, messages, reports, reorder, review and question edits, and the whole seller desk: parcels, slips, notes, shipments and events, listings, images, options, variants, stock, promo codes, shipping methods and regions, returns, review replies, question answers, the legacy item-status route) was requested with the other tenant's real ids by: the other seller, a buyer with no store, the other buyer, a seller on a buyer's data, and a guest, with a hash of 40 tables taken before and after each request. Control requests as the owner confirm the URLs are real. Mutating requests carried valid payloads, so a missing check would have changed a row.

- 396 attacker requests: 155 x 403, 83 x 404, 158 x 302 (all to `/login` or `/sell/start`); none changed any row; none returned 200.
- Owner controls: 18 x 200; `orders.returns.create` 302 (item not returnable yet); the return-photo URLs 404 in the demo because it carries no photos, so photos were tested separately below.
- Return photo (real upload): owner buyer 200, owning seller 200 (own URL), operator 200, other buyer 404, guest 404, other sellers 404 on both URLs; path traversal and out-of-range index 404.
- Mixed bindings: another store's image under my listing (cover, delete), another store's region under my method, another store's photo id in a variant row: 404 or ignored, nothing changed.
- Guest order routes: no grant, wrong token, short token, the right token on another order, the right token on an account's order: 404; a grant for order 32 does not open or act on 31 or 1 (messages, cancel, pay, reorder, report all 404).
- Seller/buyer privacy on `/orders/{id}`: a seller with a line sees no street address, phone or e-mail once nothing is left to ship and only the fulfilment details while something is.
- Operator-only routes (67 route-methods, 268 requests) as a seller, a buyer, an unverified person and a guest: 403 x 201, guest 302 to `/login` x 67; no mutation. The `operator` middleware runs before route-model binding, so an id that exists and one that does not answer alike.
- Unverified account (`email_verified_at` null): the seller desk, orders and checkout all work. That is how the app has always behaved (the User model is not `MustVerifyEmail`, accounts come from InfoDot); D-150 makes sure the flag still means something where it is trusted.

Matrix (attacker class against route-method; owner control status where a GET control exists; `-` means the pair does not apply):

| Route (method) | Owner control | Other seller | Other buyer | Buyer w/o store | Seller vs buyer data | Guest |
|---|---|---|---|---|---|---|
| `account.addresses.default` (POST) | - | - | 403 | - | 403 | 302 |
| `account.addresses.destroy` (DELETE) | - | - | 403 | - | 403 | 302 |
| `account.addresses.edit` (GET) | 200 | - | 403 | - | 403 | 302 |
| `account.addresses.update` (PATCH) | - | - | 403 | - | 403 | 302 |
| `account.returns.escalate` (POST) | - | - | 403 | - | 403 | 302 |
| `account.returns.show` (GET) | 200 | - | 403 | - | 403/404 | 302 |
| `account.saved-searches.destroy` (DELETE) | - | - | 403 | - | 403 | 302 |
| `account.saved-searches.update` (PATCH) | - | - | 403 | - | 403 | 302 |
| `alerts.destroy` (DELETE) | - | - | 403 | - | 403 | 302 |
| `order-items.cancel` (POST) | - | - | 403 | - | 403 | 302 |
| `orders.items.receive` (POST) | - | - | 404 | - | 404 | 302 |
| `orders.message` (POST) | - | - | 404 | - | 404 | 302 |
| `orders.pay` (POST) | - | - | 404 | - | 404 | 302 |
| `orders.payment.check` (POST) | - | - | 404 | - | 404 | 302 |
| `orders.receipt` (GET) | 200 | - | 404 | - | 404 | 302 |
| `orders.reorder` (POST) | - | - | 404 | - | 404 | 302 |
| `orders.report` (POST) | - | - | 404 | - | 404 | 302 |
| `orders.returns.create` (GET) | 302 | - | 404 | - | 404 | 302 |
| `orders.returns.store` (POST) | - | - | 404 | - | 404 | 302 |
| `orders.show` (GET) | 200 | - | 403 | - | 403 | 302 |
| `questions.destroy` (DELETE) | - | - | 403 | - | 403 | 302 |
| `return-photos.show` (GET) | 404 | - | 404 | - | 404 | 404 |
| `reviews.destroy` (DELETE) | - | - | 403 | - | 403 | 302 |
| `reviews.reply` (POST) | - | 403 | - | 403 | - | 302 |
| `reviews.update` (PATCH) | - | - | 403 | - | 403 | 302 |
| `seller.order-items.update-status` (PATCH) | - | 403 | - | 403 | - | 302 |
| `seller.orders.cancel` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.orders.deliver` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.orders.note` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.orders.pack` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.orders.ready` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.orders.ship` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.orders.show` (GET) | 200 | 404 | - | 302 | - | 302 |
| `seller.orders.slip` (GET) | 200 | 404 | - | 302 | - | 302 |
| `seller.products.attributes` (PATCH) | - | 403 | - | 403 | - | 302 |
| `seller.products.destroy` (DELETE) | - | 403 | - | 403 | - | 302 |
| `seller.products.duplicate` (POST) | - | 403 | - | 403 | - | 302 |
| `seller.products.edit` (GET) | 200 | 403 | - | 403 | - | 302 |
| `seller.products.images.cover` (PATCH) | - | 403 | - | 403 | - | 302 |
| `seller.products.images.destroy` (DELETE) | - | 403 | - | 403 | - | 302 |
| `seller.products.images.store` (POST) | - | 403 | - | 403 | - | 302 |
| `seller.products.options` (POST) | - | 403 | - | 403 | - | 302 |
| `seller.products.resubmit` (POST) | - | 403 | - | 403 | - | 302 |
| `seller.products.stock` (POST) | - | 403 | - | 403 | - | 302 |
| `seller.products.stock-history` (GET) | 200 | 403 | - | 403 | - | 302 |
| `seller.products.toggle-active` (PATCH) | - | 403 | - | 403 | - | 302 |
| `seller.products.update` (PATCH) | - | 403 | - | 403 | - | 302 |
| `seller.products.variants` (POST) | - | 403 | - | 403 | - | 302 |
| `seller.promo-codes.destroy` (DELETE) | - | 403 | - | 403 | - | 302 |
| `seller.promo-codes.toggle` (PATCH) | - | 403 | - | 403 | - | 302 |
| `seller.questions.answer` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.returns.approve` (POST) | - | 404 | - | 404 | - | 302 |
| `seller.returns.decline` (POST) | - | 404 | - | 404 | - | 302 |
| `seller.returns.photo` (GET) | 404 | 404 | - | 302 | - | 302 |
| `seller.returns.receive` (POST) | - | 404 | - | 404 | - | 302 |
| `seller.returns.show` (GET) | 200 | 404 | - | 302 | - | 302 |
| `seller.reviews.reply` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.shipments.events.store` (POST) | - | 404 | - | 302 | - | 302 |
| `seller.shipments.update` (PATCH) | - | 404 | - | 302 | - | 302 |
| `seller.shipping.destroy` (DELETE) | - | 403 | - | 403 | - | 302 |
| `seller.shipping.regions.destroy` (DELETE) | - | 403 | - | 403 | - | 302 |
| `seller.shipping.regions.store` (POST) | - | 403 | - | 403 | - | 302 |
| `seller.shipping.update` (PATCH) | - | 403 | - | 403 | - | 302 |
| `wishlist.items.alert` (POST) | - | - | 403 | - | 403 | 302 |
| `wishlist.items.cart` (POST) | - | - | 403 | - | 403 | 302 |
| `wishlist.items.destroy` (DELETE) | - | - | 403 | - | 403 | 302 |

**6 Platform (production mode).** `APP_ENV=production APP_DEBUG=false` with `route:cache config:cache event:cache` (route cache succeeds: no closure routes): `/dev/login`, `/dev/logout`, `/dev/kit`, `/dev/fake-paystack`, `/_boost/browser-logs` (GET and POST), `/telescope`, `/horizon`, `/.env` all 404; `/up` 200 ("Dot.Emall is up."); `artisan about` shows Debug OFF; headers present: `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy-Report-Only` with per-request nonce (report-only by decision, not enforced). `POST /auth/ecosystem`: read the controller and `EcosystemSsoPostTest`: the token is read from the body only, a POST needs an Origin or Referer on InfoDot's host (or Sec-Fetch-Site same-origin or same-site when neither header is sent), a refused sign-in lands on `/login` with nothing echoed, `GET ?token=` is ignored, the redirect target is restricted to a single-slash same-site path, 10 a minute; run unauthenticated here it answered 303 to `/login`. `schedule:list` shows nine tasks; each was run once with the cached config (recommendations rebuild, search reindex `--stale`, alerts evaluate, discovery prune, reconcile `--days=3`, expire unpaid (cancelled 2 orders), publish metrics (off without ingest settings: "Nothing was collected or sent"), release ledger, escalate returns): no errors. (`schedule:list` prints `emall:publish-metrics .`; the real command string has no argument and the command runs, so that is only the listing's description column.) Mail: all 13 notification classes rendered for an account and for a guest with a store name containing `&` and a message containing HTML: no error, no double escape, no unescaped markup, no leftover placeholder.

## Not fixed, and why

- **Host upload limits (owner item, D-155).** The listing page promises 4 MB per photo and six photos. PHP's defaults (`upload_max_filesize=2M`, `post_max_size=8M`) cut a 2.3 MB photo off before the app sees it. The code now explains it; the host's PHP settings still need `upload_max_filesize >= 4M` and `post_max_size >= 26M` for the cPanel account. Not verifiable here.
- **Guest-cart throttle is per IP (60 a minute across cart, checkout and delivery pages).** Shoppers behind one mobile or office address share it. A scripted run of mine hit it. Left alone: a limiter change is the buyer pass's.
- **Operator support lookups opened from a return or a report carry no purpose.** The people page asks for a purpose and logs it; the links from other operator pages open the same record with "Not given" in the access log. A purpose chosen in the search is not carried by those links. Left alone (policy).
- **"Move to cart" from a wishlist and "Buy again" do not record an add-to-cart event** (the product page and the "bought together" button do), so the add-to-cart rate and cart-abandonment are slightly undercounted.
- **Seller analytics prints a 100% return rate from 3 delivered items.** The public trust ladder withholds a rate under five orders; the seller's own page does not. Cosmetic.
- **Tap targets.** Text links on the seller dashboard cards ("Open analytics", "Open ledger", "All orders") are about 19 px tall at 375 px.
- **Validation sentences** still use Laravel's wording for some fields ("The contact phone field format is invalid.", "The selected region is invalid.").
- **`verified` middleware is a no-op** (D-150). Registration is closed and accounts come from InfoDot, so there is no unverified sign-up to block; it would only matter if local sign-up opened.

## Not tested

- Concurrency on a real database: the dev server is single-threaded and SQLite ignores row locks. The suite's PostgreSQL parallel tests are the evidence (the integration notes record them); I did not re-run them.
- The signed-in pages under the production route and config caches (there is no dev sign-in in production mode). The integration smoke records 138 signed-in pages under those caches; I did not repeat it. Public pages, headers, scheduler and mail were checked under them.
- HSTS over real TLS (the code sends it on secure requests only; this server has no TLS).
- A real Paystack, real e-mail delivery, real carrier links (the carrier URL templates are an owner item to re-verify, D-?? in the plan).
- Seller-side review photos (`/review-photos/{photo}`): the demo carries none.
- Two seller team members acting on one store (a store has exactly one owner in this build).
- A store logo or banner upload: only its refusal messages (unit test); the happy path was not driven in the browser.
- Light theme (the pane followed the app's dark theme throughout); Safari and Firefox.

## Counts

Full suite after the last code change, SQLite in memory: **1,571 tests, 1,533 passed, 38 skipped (the PostgreSQL-only tests), 0 failed**, 9,600 assertions. PostgreSQL not run. Ten tests are new (`tests/Feature/Qa/SellerQaRegressionTest.php`); each fails without its fix (checked by stashing the fix for D-150, D-151, D-152, D-153, D-154 and D-155).
