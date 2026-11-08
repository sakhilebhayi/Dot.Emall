# R-43 integration notes for the feature agents

State after the integration pass (branch `feat/r43-int`). What is wired, what is not, and what each existing page still needs.
Decisions D-50 to D-62 are in `R43_DECISIONS.md`; the demo is described in `R43_DEMO.md`.

## The foundation, in one picture

- **Catalogue.** One category tree (`Catalogue\CategoryTree`, also the discovery lookup). Brands, structured attributes and
  variants are real tables read by `DatabaseCatalogueSource`; the search index (`search_documents`, `search_document_attributes`)
  holds title, brand, category path, attributes, variant option values, store and region, and stays current on any of those changing.
  `php artisan emall:search-reindex` rebuilds it; on the seeded demo it takes under a second.
- **Money and delivery.** `CartQuote` and `ShippingQuote` are the only source of a total. A parcel's delivery fee stays in the ledger
  when its only line is returned after delivery.
- **One of each:** price history (`PriceHistory`), notification preferences (`NotificationPreferences`, categories transactional /
  alerts / marketing), completed-line definition (`OrderStatus::completedValues`), store rating (`StoreRatings`), cart (`CartManager`).
- **Verified:** 1,164 tests: SQLite 1,126 passed and 38 skipped (the PostgreSQL-only ones), PostgreSQL 1,157 passed and 7 skipped, none failed; the signed-in smoke with route, config and event caches passes 43 pages.

## Registered by this pass (pure aliases, `// --- integration ---` in `routes/web.php`)

`search` (GET `/search`, the products page), `account.home` (GET `/account`), `seller.dashboard` (GET `/sell/dashboard`) and
`sell.start` (GET `/sell/start`). Replace each with the real page and delete its line.

## Routes still pending (DESIGN.md route table)

`search` as a real page (see below), `categories.index`, `categories.show`, `stores.index`, `stores.show`, `checkout.show`,
`wishlist.index`, `account.returns`, `account.saved-searches`, `account.addresses`, `account.alerts`, `seller.returns`,
`seller.shipping`, `seller.analytics`, `operator.reports`, `operator.metrics`, `help.index`, `help.delivery`, `help.returns`,
`help.contact`, `help.sellers`, and `guest.orders.show` (the guest link answers a small JSON summary until it exists). Every pending
link renders as `#` with `data-pending-route` (28 on a buyer's page, 7 on the seller desk, 6 on the operator desk).

## Page by page: what still uses old inline styles or old logic

"Kit" means the page uses a shell layout and the `ds` components; "old" means inline `style=""` and the pre-R43 `dot-card` look.

| Page | State | Leftovers |
|---|---|---|
| `/` welcome | kit | Live latest products only. No `Homepage` service sections yet (continue, interests, trending, categories, collections, popular searches, local, reorder): `Discovery\Homepage::for($actor, $region)` is ready and tested. |
| `/products` (index) | kit, **legacy search** | Still a name `LIKE` with a category facet. Replace with `SearchService::search(SearchRequest::fromArray($request->query()), Actor::forRequest($request))`: filters, facets, sorts, "did you mean", applied-filter chips and `why()` all exist. Saved-search digests link here with `q` and filters that the page ignores until it is replaced. |
| `/products/{id}` | kit | No variant picker (a multi-variant product answers "Choose Size and Colour first." on add); no "from" price; no reference price (`ReferencePrice::for`), store trust (`StoreTrust::for`), delivery estimate, recommendations (`Recommender::forProduct`), Q&A, review votes/photos/responses, wishlist and alert buttons. Add `RecentlyViewed::view` on show. |
| `/cart` | **old** (45 inline styles) | Reads `$item->product->price`; no `CartQuote`, no delivery per parcel, no address book, no `expected_total_cents`, no guest cart, no `PriceChangedException` handling. The checkout form is the legacy one-line address. |
| `/orders` | kit shell, old table | No delivery, tracking, return or timeline columns. |
| `/orders/{id}` | **old** (55 inline styles, no layout slot) | Now shows the Delivery row; still no parcels (`OrderAccess::present()['groups']`), shipment and tracking events labelled "from the seller", status history, return actions, receipt confirmation, or estimate labels. |
| `/dashboard` | **old** (59 inline styles) | Both buyer and seller overview; `seller.dashboard` and `account.home` alias it. Needs splitting and the ledger balance (`LedgerService::balance`). |
| `/sell/orders` | kit shell, old table | No ship form (`ShipmentService`), no per-parcel actions, no return queue. |
| `/sell/products` (create, edit, index) | kit shell, **old** (24, 40, 19) | No brand, attributes (`ProductAttributes`), options and variants (`ProductVariants`), per-variant stock with `stock_was`, low-stock threshold, fee disclosure (`SellerFees::text`). |
| `/sell/store/edit`, `/sell` | kit shell, old (13, 9) | Store profile fields from `StoreOnboarding` (legal name, region, policies, VAT), shipping methods and regions, the review/submit flow. |
| `/sell/promo-codes` | kit shell, old (25) | `free_shipping` option. |
| `/operator/products`, `/operator/payments` | kit shell, old (19, 61) | No return queue (`ReturnService::operatorQueue`), store review queue (`StoreOnboarding::reviewQueue`), moderation queue (`ModerationQueue`), reports, metrics (`CommerceMetrics`), search zero-result report. |
| Auth, profile, teams, API tokens, 4xx/5xx | kit | Done by the shell; Jetstream forms inside profile and teams keep their stock markup. |
| Notification bell | old (9 inline styles) | Renders every notice type through `title`, `message`, `url`; new notices carry `action`. |

## Behaviour notes that will bite

- **Variants.** A cart line is a variant. `POST cart.store` needs `variant_id` for a product with several active variants.
  The product row's price is the cheapest active variant's; its stock is the active variants' sum.
- **Search.** `emall:search-reindex --stale` runs every five minutes; the observers refresh a product's document when its name,
  description, category, brand, SKU, attributes or variant options change. Facet counts are cached 60 seconds in production only.
  `attr[key][]` values are the displayed text (`16 GB`).
- **Alerts and notices.** Alerts are in the app by default; e-mail is opt-in per person (`NotificationPreferences::set`).
- **Wishlist to cart** may answer `needs_variant`: send the shopper to the product page.
- **Guest orders** have no user; `OrderAccess::presentGuest` is the buyer's view. Sign-in attaches them by verified e-mail.
- **Return photos** have no upload endpoint (D-25).
- **Prices** are `decimal(12,2)` on rows and integer cents in services; never mix them in a view.
- **The demo's pictures** are generated renders; nothing in `public/storage` belongs to git.
- **Local servers.** `/dev/login/{key}@emall-demo.test?to=/path` signs anyone in; the routes do not exist in production.

## Not integrated, on purpose

Carrier APIs, automatic payouts, Paystack transfers, a model-written assistant answer without a key, Dot.Notify without its env
settings, InfoDot company "verified" state (the sign-in payload has no such field), VAT invoices. Owner items from D-1, D-2, D-8,
D-24 and D-26 still stand.

## W3 discovery pages (branch feat/r43-w3)

Routes in the `// --- w3 ---` block: `home` (`/`), `search`, `categories.index`, `categories.show`, `collections.index`, `collections.show`, `assistant`, `recently-viewed.index`, `recently-viewed.clear`, `region.update`, `account.saved-searches` (+ `.store`, `.update`, `.destroy`). `products.index` is the same controller as `search` (decisions D-70 to D-77).

Reusable pieces for other pages: `<x-disc-rail :set="$recommendationSet" />` (props in its header; the product page adopts it for `Recommender::forProduct()`), `<x-disc-card :product="$p" :why="..." />` (has the `wishlist` slot), `<x-disc-region-chip />` for a layout's `location` slot, `App\Support\ShopperRegion::current()`, `App\Support\SearchLinks`.
