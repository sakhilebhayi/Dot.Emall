# R43 Product and UX audit: Dot.Emall

Audited 2026-11-08 on branch `feat/r43-ux0` (Laravel 13.37, PHP 8.5, Livewire 3, Jetstream teams, Tailwind), against the
demo marketplace in [R43_DEMO.md](R43_DEMO.md) (4 stores, 60 products, 14 orders in every state, 15 reviews, 11 accounts).
The backend and commerce engineering are audited separately (branch `feat/r43-be0`); this document is product and UX.

**Method.** Every page was opened as guest, brand-new user, buyer, seller and operator at 1440 px and 390 px, in light and
dark colour scheme, in a real Chrome driven by Playwright (201 captured pages/states). axe-core 4.x was run on every page;
console errors, failed requests, horizontal overflow and tap targets were measured on every page. The buyer flow (search,
filter, add to cart, quantity update, over-stock attempt, bad and good promo, checkout, review post/delete) and the seller flow
(status change, listing create with validation errors and a photo, edit, promo create with bad and duplicate codes, operator
queue) were clicked through. Paystack was exercised with a test-mode key pointed at an unreachable local address, so the
"gateway down" and "could not confirm" states were seen without any external call.

**Screenshots** are in `/private/tmp/claude-501/-Users-sakhilebhayi-Dot/e1920f4e-d237-4804-8adb-eb4bdf16c1c4/scratchpad/emall-audit-shots/`
(PNG, full page, with a JSON of measurements per page in `results/`). Naming: `<viewport><scheme>-<role>-<page>.png`, where
viewport is `d` (1440) or `m` (390), scheme is `l` or `d`, role is `g` (guest) or the demo user key. Example:
`dl-thandi-order-5-multi.png` is desktop, light, Thandi, order 5. `-f-` marks a step inside a flow.

**Not trusted without proof.** A page or button existing was never taken as proof that it works. Where something works, the
screenshot or measurement that shows it is named. Where it could not be tested, section 9 says so.

---

## 1. Page and route inventory

| Route | Who | What it is | Screenshots |
|---|---|---|---|
| `/` | all | Marketing page for sellers and the platform (hero, "two-sided marketplace", ecosystem). No products. | `dl-g-welcome-scrolled`, `ml-g-welcome` |
| `/products` | all | Grid of 12 per page, name search, category select, Previous/Next. No sort, no price filter. | `dl-g-products`, `dl-thandi-products-search`, `dl-thandi-products-nomatch`, `dl-thandi-products-cat`, `dl-f-products-page2`, `ml-g-products` |
| `/products/{id}` | all | Product detail: one image plus thumbnails, price, stock, add to cart (signed in) or "Sign in to buy", reviews. | `dl-thandi-product-1`, `dl-thandi-product-oos`, `dl-g-product-detail`, `ml-g-product-detail`, `dl-thandi-product-pending-404` |
| `/login`, `/register` | guest | Jetstream sign-in. `/register` redirects to `/login` (sign-up is closed; accounts come from InfoDot). | `dl-g-login`, `dd-g-login-view`, `dl-g-register` |
| `/cookies`, `/terms-of-service`, `/privacy-policy` | all | Legal pages. | `dl-g-cookies`, `dl-g-terms`, `dl-g-privacy` |
| `/dashboard` | signed in | "Seller desk" for everyone, buyers included. | `dl-thandi-dash`, `dl-lerato-dash`, `dl-nomsa-dash`, `dl-operator-dash` |
| `/cart` | signed in + verified | Cart lines, subtotal, and the whole checkout form (address, phone, promo) on the same page. | `dl-f-cart-1item`, `dl-f-cart-2stores`, `dl-f-cart-badpromo`, `dl-f-cart-overstock-error`, `dl-thandi-cart-empty`, `dl-pay-cart-testmode` |
| `/orders`, `/orders/{id}` | buyer (seller and operator see their view of the same URL) | Order list and detail with payment banner, lines, cancel item, totals, shipping details. | `dl-thandi-orders`, `dl-thandi-order-5-multi`, `dl-thandi-order-11-failed`, `dl-thandi-order-1-delivered`, `dl-sipho-order-10-awaiting`, `dl-sipho-order-6-shipped`, `dl-naledi-order-8-partial`, `dl-naledi-order-12-cancelled-unpaid`, `dl-ayesha-order-3-promo`, `dl-ayesha-order-13-new`, `dl-pieter-order-14-legacy`, `dl-pieter-order-7-processing` |
| `/orders/{id}/payment/callback` | buyer | Paystack return. | `dl-pay-callback-with-ref`, `dl-f-payment-callback-noref` |
| `/sell`, `/sell/store/edit` | signed in | Open a store (name, description); edit/close it. No logo field. | `dl-nomsa-sell-create`, `dl-lerato-store-edit`, `dl-f-lerato-store` |
| `/sell/products` (+ `create`, `{id}/edit`) | seller | Listing table, create with up to 6 photos, edit, photos add/remove/cover, activate/deactivate, delete. | `dl-lerato-sell-products`, `dl-f-lerato-create-empty`, `dl-f-lerato-create-negprice`, `dl-f-lerato-created-product`, `dl-lerato-sell-edit-prod` |
| `/sell/orders` | seller | Orders containing the seller's lines; "Manage" opens `/orders/{id}`. | `dl-lerato-sell-orders`, `ml-lerato-order7-seller`, `dl-f-johan-order5`, `dl-f-johan-order5-after` |
| `/sell/promo-codes` | seller | Create and delete store promo codes. | `dl-lerato-promo`, `dl-f-lerato-promo-over100`, `dl-f-lerato-promo-dup` |
| `/operator/products` | operator | Approve or reject new listings. | `dl-f-op-products`, `dl-operator-op-products` |
| `/operator/payments` | operator | Payments desk: gateway state, payments to review, refunds owed, unmatched events. | `dl-f-op-payments`, `dl-operator-op-payments` |
| `/user/profile`, `/teams/{id}`, `/teams/create` | signed in | Jetstream account, password, 2FA, sessions, delete account; teams. | `dl-nomsa-profile` (unreadable), `dd-nomsa-profile` (readable), `ml-nomsa-profile`, `dl-nomsa-team` |
| `/user/api-tokens` | signed in | Returns 404 in this build (API tokens feature is off). | `dl-nomsa-api-tokens` |
| notification bell (all signed-in pages) | signed in | Livewire panel of the last 10 notifications. | `dl-notifications-open` |
| Errors 403, 404, 419, 429, 500, 503 | all | Shared "ecosystem" error layout, quite good. | `dl-err-403` ... `dl-err-503`, `ml-err-*` (rendered from the real views; see section 9) |
| `/dev/login/{id\|email}`, `/dev/logout` | local only | Added in this audit for demos; absent in production. | n/a |

Also present but not pages: `POST /webhooks/paystack`, `POST /csp-report`, `POST /auth/ecosystem` (InfoDot sign-in).

---

## 2. Feature inventory

Classes: **FO** fully operational, **NI** operational but needs improvement, **PI** partially implemented, **UI** UI-only or
disconnected, **BR** broken, **DU** duplicated, **MN** missing but necessary.

| Feature | Class | What was observed |
|---|---|---|
| Catalogue (listings) | NI | Listing, moderation, soft-delete, snapshotting all work (`dl-lerato-sell-products`, `dl-f-op-products`). Listing grid has no out-of-stock or sale badge (`/products?q=vase` card shows only "No reviews yet"). |
| Categories | **BR/MN** | The category tree exists in the schema, but nothing in the application creates a category: no admin screen, no seeder, no seller input (`grep StoreCategory` finds only reads). A fresh production database has an empty filter. With data, parent categories show `Electronics (0)` and `Home & Kitchen (1)` while their children hold 13 and 14 products (`dl-f-products` options); counts include listings that are not browsable (`Beauty & Wellness (14)`, 13 visible). The tree is shown flat and alphabetical. |
| Variants (size, colour) | MN | None. One price, one stock number, one SKU per listing. |
| Search | PI | Substring match on product name only (`ProductController@index`, `LIKE %q%`). No description, store, SKU or category match; no relevance order; no suggestions; no "did you mean". Wiki lists Scout/Meilisearch as planned and unwired. |
| Filters and sort | PI | Category select only. No sort control at all (`select[name=sort]` count 0), no price range, no rating, no in-stock, no store filter. Newest first is the only order. |
| Product page | NI | Works (`dl-thandi-product-1`). Missing: store link, breadcrumb, delivery or returns information, verified-purchase badge, rating breakdown, related products, share, image zoom; fixed 340 px image column; every page title is the static "Dot.Emall". |
| Cart | NI | Add, update, remove, stock limits and out-of-stock handling all enforced server side (`dl-f-cart-overstock-error`: "Only 3 of ... in stock"). Signed-in only; no guest cart. No per-store grouping, no saved-for-later, no promo preview. |
| Checkout | NI | Idempotent, one page, order placed from the cart page (`dl-f-after-checkout`). Free-text address only, no address book, no shipping method or cost, no review step. Entered address is lost when checkout bounces on a bad promo (`dl-f-cart-badpromo`: field is empty again). |
| Payments (Paystack) | NI | Return states are handled clearly: awaiting payment, test-mode tag, "could not reach Paystack, order saved, press Pay now", "could not confirm" (`dl-pay-checkout-gateway-down`, `dl-pay-callback-with-ref`, `dl-sipho-order-10-awaiting`, `dl-thandi-order-11-failed`). Test mode only; real Paystack never exercised end to end. Seller payouts, splits, commission and KYC are not built (wiki). |
| Orders (buyer) | NI | List and detail correct for all 10 states (`dl-thandi-order-5-multi`, `dl-naledi-order-8-partial`). No order timeline, invoice or receipt, reorder, or contact-seller. A cancelled unpaid order shows "Total (due) R0.00" (`dl-naledi-order-12-cancelled-unpaid`). |
| Order tracking | **MN** | Status chips only (pending, processing, shipped, delivered). No tracking number, courier, estimated delivery, status history or timestamps per step. The seller form has no tracking field (`input[name*=track]` count 0). |
| Returns and refunds | PI | Refund happens only when a paid line is cancelled before shipping (refund note on `dl-naledi-order-8-partial`; operator refund desk `dl-operator-op-payments`). There is no return request, no reason, no RMA, nothing after delivery. |
| Seller management | NI | Open store, edit, close, list, edit, deactivate, delete, promo codes all work. Seller sees no rejection reason (stored in `rejected_reason`, never rendered), is not notified of approve or reject, listing table has no photo, search, filter or pagination, and the status label is raw `Pending_approval` (`dl-lerato-sell-products`). |
| Inventory | PI | Stock number per listing; stock decrements at checkout and restores on cancel (verified through the seeded flows); low-stock count on the seller dashboard. No stock history, no alerts, no bulk edit or import, no reserve of stock while a payment is pending beyond the 60-minute expiry. |
| Shipping and delivery | **MN** | No shipping profiles, rates, zones, courier, delivery estimate, pickup, or per-seller shipping on a multi-seller order. |
| Promotions | NI | Store-scoped codes (percent or fixed, limit, expiry) work, including exact per-line splitting (`dl-ayesha-order-3-promo`, `dl-f-after-checkout`). Server validation is good (`dl-f-lerato-promo-over100`, `dl-f-lerato-promo-dup`). No edit or disable, no usage list, no automatic or timed sale, no marketplace-wide codes, discount not shown before placing the order. |
| Reviews and ratings | NI | Verified-purchase only, one per product, post and delete work, aggregate recomputed (`dl-f-review-posted`). Unverified rows render exactly like verified (`dl-thandi-product-1`: no badge); no edit, sort, helpful vote, seller reply, photos, or report. |
| Accounts | NI | Sign-in via InfoDot SSO; profile, password, 2FA, sessions, delete account exist but are unreadable in light colour scheme (`dl-nomsa-profile`). No address book, no communication preferences. |
| Addresses | **MN** | Single free-text address typed at each checkout. |
| Wishlists | **MN** | None. |
| Notifications | NI | In-app bell works (`dl-notifications-open`), per-order and per-line events for buyers and sellers. No email for any order event (the only mailable is the team invitation), no push, no preferences, no link from the bell to the right place was tested beyond the order URL, "Mark all read" works. |
| Recommendations | **MN** | None: no related, popular, recently viewed, or "bought together". |
| Images | NI | Up to 6 photos, content-checked, re-encoded, EXIF stripped, cover selection, 4 MB limit (`dl-lerato-sell-edit-prod`). Local public disk only; one size (up to 1600 px) served everywhere; no thumbnails, no `srcset`, no `loading="lazy"`, no `width/height` (layout shift). Store logo column exists but no upload and nothing renders it. |
| Mobile | NI | Responsive shell, drawer, 44 px buttons and no horizontal overflow on any of the 201 captures (`ml-drawer-open`, `ml-thandi-order-5-multi`, `ml-g-products`). Weak spots in section 6. |
| InfoDot SSO | FO (not retested) | `POST /auth/ecosystem` and the OIDC redirect are covered by the existing test suite; the audit used the local dev sign-in instead. |
| Dot.Analytics | PI | Daily aggregate snapshots, queued, only when URL and secret are set; nothing visible in the product. |
| Dot.Notify | **MN** | Not integrated. Emall has its own database notifications. |
| Dot.Files | **MN** | Not integrated. Photos live on Emall's own public disk. |
| Dot.Pulse | **MN** | Not integrated (no store or product posts, no follows). |
| Dot.Brain (DKP packs) | UI-ish | One signed incident pack was produced once; nothing is transmitted (wiki). |
| "Realtime (Reverb)" and "AI (Anthropic)" in wiki | **UI / false claim** | `BROADCAST_CONNECTION=reverb` is set but there is no Echo or broadcast code (`resources/js/app.js` is empty); no AI feature exists in the app (only a `config/services.php` key). |
| Legacy navigation (Jetstream `navigation-menu`) | DU | Rendered in addition to the new sidebar (`.shell-legacy-nav`): a white strip under the top bar on every signed-in page and a second, hidden-looking set of links at the end of the tab order (`dl-thandi-products`, keyboard test). |

---

## 3. Top 30 problems

Severity: **P0** blocks a credible marketplace or locks users out, **P1** serious friction or trust loss, **P2** polish and risk.

### P0

1. **New people cannot get in, and the page does not say so.** "Open a store", "Create account" (welcome page, hero and footer), "Create an account" (sidebar) and "Create one" (product page) all go to `/register`, which redirects to `/login` because sign-up is closed (`dl-g-register` lands on the login page). Nothing explains that accounts come from InfoDot. No self-serve buyer or seller can start. Evidence: link list from the welcome page; `routes/web.php` last block.
2. **The public storefront is the seller back office.** `/products` and `/products/{id}` render inside the dark sidebar app shell with "Dashboard / Selling / Operator" navigation (`dl-g-products`). There is no storefront header, search in the header, category navigation, featured collection, or brand voice for shoppers. The welcome page sells the platform to sellers and shows no products (`dl-g-welcome-scrolled`).
3. **Account pages are unreadable in light mode.** Profile, password, 2FA, sessions, delete account and team pages render near-black or white-on-white text with blank buttons for anyone whose system or stored theme is light (`dl-nomsa-profile` vs `dd-nomsa-profile`; contrast ratios of 1.08 to 1.12 in `results/dl-nomsa-profile.json`). The shell is fixed dark, the Jetstream pages are not.
4. **There are no categories to browse.** No UI, seeder or API creates one, so a fresh deployment has an empty category list; with data, parent categories read `(0)` (see feature table).
5. **No shipping at all.** No cost, method, courier, estimate or tracking number, and the buyer types a free-text address. For a multi-seller order the buyer cannot know what will be charged, who ships, or when.
6. **No email for orders.** Placement, payment, shipping, delivery, cancellation and refund reach people only through the in-app bell. The only mailable in the codebase is the team invitation.
7. **The whole UI depends on the Tailwind runtime CDN.** `https://cdn.tailwindcss.com` is loaded in `layouts/app.blade.php` and logs "should not be used in production" on 44 of the captured pages. It compiles CSS in the browser on every visit, fails entirely if the CDN is blocked, and conflicts with the strict CSP the platform is moving to. Fonts and the icon font also come from Google at runtime.

### P1

8. **Buyers land on a "Seller desk".** `/dashboard` for a buyer says "You are not selling on Dot.Emall yet" with an "Open a store" call to action (`dl-thandi-dash`). It should be an account home: orders, delivery status, saved items.
9. **Discovery is thin.** No sort, no price, rating, in-stock or store filter; name-only search (section 2). A 60-product catalogue is already hard to scan.
10. **Out-of-stock and low-stock listings look normal in the grid** (vase card), and the product page says "Out of stock" only after you open it (`dl-thandi-product-oos`).
11. **Checkout loses the address on any error** (`dl-f-cart-badpromo`), shows no discount until the order is created, and does not group lines by store even though each store ships separately.
12. **No returns.** After delivery the buyer has no path to report a problem or ask for a refund. Cancel exists only before shipping.
13. **Sellers cannot see why a listing was rejected, and are not told.** `rejected_reason` is saved by the operator (`ProductModerationController`) and never shown or notified. A rejected seller sees a red "rejected" chip and nothing else.
14. **The operator approves listings blind.** The queue shows name, price, store and owner only: no photo, description, category or link to the listing (`dl-f-op-products`). There is no queue age, no bulk action, and no tooling for stores, users, orders or disputes.
15. **Seller order handling is minimal.** A seller can jump a line from pending straight to delivered; there is no tracking number, packing slip, seller note or customer message. Sellers also see awaiting-payment orders in their to-fulfil list (`dl-lerato-sell-orders`, order 10).
16. **No trust signals on the product page.** No verified badge (the three unverified demo reviews are indistinguishable from verified ones, `dl-thandi-product-1`), no seller rating or page, no store logo (the `logo_path` column is never uploaded or rendered), no delivery or returns promise.
17. **SEO is absent.** Every app page has the same `<title>Dot.Emall</title>`; no meta description, canonical, Open Graph, structured data or sitemap for products; `robots.txt` allows everything.
18. **Accessibility basics.** No skip link; no `<h1>` on 80 of 201 captures (page titles are styled `div`s); icon ligature text ("dashboard", "storefront") is read aloud before every nav label; 128 captures fail colour contrast, chiefly `#71717a` on `#09090b` (4.11:1) and on `#141416` (3.8:1) for secondary text; `aria-label` on a pagination `<span>`; unlabeled duplicate `nav` landmarks; a bell button whose accessible name does not match its visible text.
19. **Two products' worth of visual identity.** The welcome, login and error pages are warm paper, ink blue and gold with Bricolage Grotesque; everything after sign-in is near-black with orange and Syne; Jetstream pages use grey Tailwind defaults (section 5).
20. **Price legibility.** Prices are set in Syne ExtraBold, where the zero reads as a lowercase "o": R749.00 looks like "R749.oo" (`dl-thandi-product-1`, every product card). This is the most important number on the page.
21. **A white strip and a duplicate nav on every signed-in page** (`dl-*` screenshots, 10 px band under the top bar), caused by the legacy Jetstream navigation rendered next to the new shell.

### P2

22. **The welcome page talks about internals.** Copy about Knowledge Packs, "the second platform to publish a signed activity pack", shared PostgreSQL and `OrderPlaced` events (`dl-g-welcome-scrolled`); a fake "Order #2291 Pending" card in the hero; "Browse the marketplace" scrolls to `#buyers` instead of opening `/products`; the SELL and SHOP links and gold labels fail contrast on the hero photo (2.3 to 2.5:1).
23. **Seller listing table is a bare list.** No photo, search, filter, sort, pagination (`->get()`), inline stock edit, or bulk actions; raw status text `Pending_approval`.
24. **Cancelled unpaid order shows "Total (due) R0.00"** and keeps `payment_status = pending` in the database (found on seeded order 12; for the backend audit).
25. **Form styling is inconsistent.** Listing and promo forms use native white inputs on the dark shell (`dl-f-lerato-create-negprice`), while cart and store forms use the dark `.dot-input` style.
26. **No lazy loading, dimensions or responsive images** on any product image; every card downloads the full photo.
27. **Mobile gaps.** Pagination is Previous/Next only with no page count (`ml-g-products`); the add-to-cart control is not sticky on a long product page; the cart's checkout form is long and sits below the lines on a 390 px screen.
28. **Reviews cannot be edited** and are deleted through a browser `confirm()` dialog; no sort or "most recent" label.
29. **Wiki is stale.** It still says Laravel 12 and PHP 8.4 (the app runs Laravel 13.37) and lists Reverb and an Anthropic model as architecture; none is used.
30. **Many pages rely on inline styles** (61 on the dashboard, 56 on order detail, 49 on product detail): there is no component library for cards, buttons and chips beyond three CSS classes, so every fix is repeated per page and `ResponsiveShellTest` has to assert on class names.

---

## 4. Current visual language and its inconsistencies

- **Shell (signed-in, public storefront):** `resources/views/layouts/app.blade.php`. Fixed dark (`#09090b` page, `#0d0d10` sidebar, `#141416` cards), orange accent (`#f97316`, `#f59e0b`), Syne for headings and prices, Inter for body, JetBrains Mono for figures, Material Symbols icons. Tailwind through the CDN with `preflight: false`. The comment in the file says the dark mode toggle "mainly affects Jetstream-rendered pages", so the toggle does not change the shell.
- **Marketing and auth:** `resources/views/welcome.blade.php`, `layouts/guest.blade.php`, `errors/_ecosystem-layout.blade.php`. Paper (`#f5f4ef`), ink (`#1c2036`), gold, Bricolage Grotesque, Hanken Grotesk, DM Mono, built with Vite and `resources/css/app.css`. This is the brand the logo comes from.
- **Jetstream pages:** `profile/*`, `teams/*`, `api/*`, `navigation-menu.blade.php`. Tailwind utility classes with `dark:` variants, no shared tokens, rendered on the fixed-dark shell.
- **Per-page styling:** `dashboard.blade.php`, `operator/payments/index.blade.php`, `orders/show.blade.php`, `products/show.blade.php`, `cart/index.blade.php`, `products/edit.blade.php` and others carry 40 to 61 inline `style=` attributes each; `products/create.blade.php`, `promo-codes/index.blade.php` and `products/edit.blade.php` style inputs differently from the `.dot-input` class used in `cart/index.blade.php` and `stores/edit.blade.php`.
- **Status chips** are consistent (`components/status-pill.blade.php`), and the order and payment states have clear plain-English copy. That consistency is worth keeping.
- **No design tokens file**, so colours are repeated as literals (`#f59e0b` amber on prices, `#f97316` orange on buttons, `#c99a2e` gold on the welcome page).

---

## 5. Mobile and accessibility findings

**Mobile (390 px, real device emulation with touch).**
- No horizontal overflow on any page; the drawer opens, traps nothing, and closes on Escape (`ml-drawer-open`).
- Primary buttons meet 44 px (the shell enforces it for `main .dot-btn` and submit buttons). The only large clusters under 44 px are the 24 ecosystem chips on error pages.
- Product grid is two columns and legible (`ml-g-products`). Order and seller pages stack well (`ml-thandi-order-5-multi`, `ml-lerato-order7-seller`); on seller order pages the line price floats with an odd left indent and the net total heading wraps awkwardly ("Your items, net R828.00 of promo").
- Pagination is Previous/Next only. No sticky buy bar. Cart and checkout are one very long page.
- Jetstream pages on mobile (`ml-nomsa-profile`) share the light-mode readability problem.

**Accessibility (axe-core on 201 captures; counts are pages affected).**
- `color-contrast` 128 pages: `#71717a` text on `#09090b` is 4.11:1 and on `#141416` 3.8:1 (secondary text, labels, "No reviews yet", table headers); gold on paper 2.51:1 on the welcome page; Jetstream pages in light mode as low as 1.08:1.
- `page-has-heading-one` 80, `landmark-unique` 76 (two `nav` landmarks without names: sidebar and legacy), `label-content-name-mismatch` 44 (notification bell), `region` 22, `landmark-one-main` 9 (legal pages), `empty-table-header` 6 (order tables), `aria-prohibited-attr` 4 (pagination).
- No skip link. Focus outlines are present on every tab stop tested (14 stops on `/products`), but the tab order passes through a duplicate legacy navigation after the notification bell. Icon ligature names are not hidden from assistive technology.
- Keyboard-only use of the main flows was exercised for focus visibility only; a screen-reader pass was not done.
- Console: the CDN warning on 44 pages; 403 and 404 resource lines only on the error pages themselves; no JavaScript exceptions; no failed network requests apart from a cancelled favicon preload.

---

## 6. First login, role by role

- **Guest.** Lands on a marketing page that explains the platform to sellers. "Shop" opens a dark product grid with a sidebar that offers "Sign in" and "Create an account" (dead end). A product page offers "Sign in to buy"; the return URL is remembered. The cart, orders and dashboard all bounce to `/login` (`dl-g-cart-redirect`).
- **Brand-new user (Nomsa).** Arrives on `/products` (or `/dashboard`). The dashboard says "Seller desk: You are not selling yet" with two buttons (`dl-nomsa-dash`). Sidebar offers Dashboard, Browse, Cart, My Orders, "Become a seller", Profile. Cart and orders pages show empty states (`dl-nomsa-cart`, `dl-nomsa-orders`). "Become a seller" is one short form (name, description) and the store is live immediately; only listings are reviewed. Profile is unreadable in a light browser.
- **Buyer (Thandi).** Dashboard is still the seller desk with "My purchases (3)". The useful parts are Browse, Cart and My Orders. Order pages are clear about payment and per-line status. The bell carries order updates.
- **Seller (Lerato).** Dashboard is a good seller desk: net delivered value, orders, to fulfil, listings with low stock, rating, store card, recent orders, and a one-line summary of what needs attention (`dl-lerato-dash`). Listing creation is quick. Gaps: no logo, no rejection reasons, no tracking, no listing photos in the table.
- **Operator (Marcus).** Dashboard is the seller desk with no store. The sidebar adds "Listing review" and "Payments". The payments desk is clear about gateway state, review items and refunds owed (`dl-f-op-payments`). There is nothing for stores, users, orders, categories or reports.

---

## 7. What is good and must be kept

1. **Commerce integrity that shows in the UI.** Server-side stock checks with plain messages (`dl-f-cart-overstock-error`), idempotent checkout, per-line cancellation with automatic re-pricing, exact promo splitting, order lines that snapshot name, store and price. Keep every one of these behaviours.
2. **Payment copy and states.** Awaiting, failed, paid, partially refunded, refunded, cancelled and "could not reach Paystack, your order is saved" are each worded in plain language with the right action (`dl-sipho-order-10-awaiting`, `dl-naledi-order-8-partial`, `dl-pay-checkout-gateway-down`). The test-mode tag is honest.
3. **Seller privacy by design.** Sellers see only their own lines and buyer details only while a line is left to ship ("Hidden" on finished orders).
4. **The seller dashboard summary line** ("2 orders waiting on you to fulfil. 1 order awaiting the buyer's payment: do not ship it yet.") is exactly the right kind of sentence.
5. **The responsive shell**: off-canvas drawer, 44 px targets, no horizontal overflow, reduced-motion support, tested by `ResponsiveShellTest`.
6. **Error pages**: one calm, branded layout for 403, 404, 419, 429, 500, 503 with a way home (`dl-err-404`).
7. **Status chips** and order references (`#00005`), money formatting in the order's own currency, ZAR throughout.
8. **Photo pipeline**: content-sniffed, re-encoded, metadata stripped, cover selection.
9. **Strong validation messages** in the seller forms and promo creation (duplicate code, over 100 percent).
10. **Security posture**: CSRF/origin protection, throttles on checkout and webhook, signed webhook, CSP headers, policies on every route (seller editing another seller's listing returns 403, `dl-lerato-edit-other-403`).
11. **The brand mark and the welcome/auth/error visual language** are the strongest asset and should become the basis of the whole product.

---

## 8. Missing but necessary for a credible marketplace

| Item | Type |
|---|---|
| Open sign-up for buyers (or a clear "continue with InfoDot" entry that works for a first-time visitor) and an explained seller application | integration (InfoDot) + UI |
| Public storefront shell: header with search, category navigation, cart count, footer; separate from the seller back office | UI |
| Home page with real products: featured, new, best rated, by category, by store | UI |
| Category management (create, order, parent and child, slug, image), category landing pages, correct counts | schema (images, ordering, SEO fields) + UI |
| Sort, price range, rating, in-stock, store filters; better search (description, store, SKU, ranking, suggestions) | UI + schema (indexes or search engine integration) |
| Product variants and options (size, colour) with per-variant price, stock and SKU | schema + UI |
| Public store pages (logo, banner, about, policies, rating, listings) and store logo upload | schema (banner, policies) + UI |
| Shipping: profiles, rates by zone or weight, free-shipping threshold, courier, tracking number, estimated delivery, collection; per-seller shipping on mixed carts | schema + UI + integration (courier API) |
| Address book (labels, default, province and postal code fields, validation) | schema + UI |
| Guest cart that merges on sign-in | schema (or session) + UI |
| Order timeline with timestamps, tracking, invoice and receipt (PDF), reorder | schema (status history) + UI |
| Returns and refund requests with reasons, seller response, operator arbitration | schema + UI |
| Transactional email and message preferences (order, payment, shipping, delivery, refund, rejection) | integration (Dot.Notify or SMTP) + UI |
| Wishlist or saved items, recently viewed | schema + UI |
| Recommendations: related, bought together, from the same store | schema or query work + UI |
| Reviews: verified badge, sort, edit, helpful, seller reply, report, photos; store reviews | schema + UI |
| Seller tools: bulk edit and CSV import, stock history, low-stock alerts, order notes, packing slip, sales analytics, payout statement | schema + UI |
| Seller payouts, commission and KYC | schema + integration (Paystack splits or Dot.Finance) |
| Operator tools: store and user management, order and dispute view, listing review with full preview and rejection templates, category management, reports | UI + schema |
| Marketplace-wide promotions: sale prices with dates, banners, automatic discounts | schema + UI |
| SEO: per-page titles, meta, canonical, Open Graph, product structured data, sitemap | UI |
| Product image delivery: thumbnails, `srcset`, lazy loading, CDN or Dot.Files storage | schema (derived sizes) + integration |
| Design system: tokens, shared button, card, input, chip and table components, one theme across shell, auth and Jetstream pages; Tailwind compiled at build time | UI |
| Accessibility pass: skip link, headings, contrast, landmark names, icon names | UI |
| Ecosystem hooks: Dot.Pulse (follow a store, share a product), Dot.Files (media), Dot.Notify (messages), Dot.Analytics (visible seller insights) | integration |

---

## 9. What could not be tested

- Real Paystack: card entry, 3-D Secure, webhook delivery, live refunds. Only the app's own handling around an unreachable gateway was seen.
- Email: nothing is sent, so nothing could be checked (this is itself finding 6).
- Production behaviour: PostgreSQL, the production web server, queue workers and the scheduler, and the production CSP. The audit ran on SQLite with `php artisan serve`.
- InfoDot SSO sign-in itself (the local dev login replaced it).
- 419, 429, 500 and 503: Laravel 13's same-origin check lets a form post without a token through, a login lock-out could not be provoked in the time allowed, and a real 500 and a maintenance 503 were not forced. The error views were rendered from the real Blade templates inside the running site and checked visually and with axe, not reached by a real failure.
- Browsers other than Chrome; a physical phone; a screen reader; slow networks.
- Uploads larger than 4 MB, non-JPEG types and image removal beyond the one photo that was added and saved.
- Scale: pagination at hundreds of products, long names, large carts.
- The seeded "Pay now" on awaiting orders points at a placeholder Paystack checkout address (the seeder cannot mint a real one), so following it leads to a Paystack 404 page.
