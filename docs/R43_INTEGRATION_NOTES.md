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
| `/sell/orders` | **kit (W6)** | Parcel tabs, per-parcel page with shipping, tracking, delivery, cancel with reason, notes, packing slip. Done. |
| `/sell/products` (create, edit, index) | **kit (W6)** | Filters, bulk switching, options and variants with `stock_was`, specifications, photos with a drop zone, fee preview, stock history, duplicate, resubmit. Done. |
| `/sell/store/edit`, `/sell`, `/sell/start`, `/sell/store` | **kit (W6)** | Onboarding, status and checklist, profile with logo and banner, InfoDot company link. Done. |
| `/sell/promo-codes` | **kit (W6)** | Minimum spend, per-buyer cap, expiry, free delivery, conditions note, pause, usage log. The cart must print `$quote->promo->conditions` (W5). |
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

## Seller desk (W6): what other workstreams need to know

- **Routes** now real (their alias lines are gone from the `integration` block): `sell.start` (public), `seller.dashboard`, `seller.shipping`, `seller.returns`, `seller.analytics`; new: `seller.store.status`, `seller.ledger`, `seller.feedback`, `seller.orders.show`, `seller.orders.slip`, `seller.returns.show`, and the action routes in the `// --- w6 ---` block.
- **W4 (store pages):** the seller's "preview" links go through `emall_route('stores.show', $store)`. A logo and banner are stored on the **public disk** at `store-media/{store id}/logo-*` and `banner-*` (`stores.logo_path`, `stores.banner_path`); print them with `Storage::disk('public')->url()`. The store's policies, region, trading details and the InfoDot link state are all on `stores`.
- **W5 (cart, orders, returns):** (1) print the promo **conditions note** (`$quote->promo->conditions`) where a code is accepted; refusals of minimum spend and per-buyer cap use the same single message as every other refusal. (2) The return-photo upload must write under `returns/{order id}/` on the `local` or `public` disk: the seller desk streams exactly those paths. (3) `Status::ORDER` gained `ready_for_collection` and `collected`; if you added them too, keep one. (4) A buyer-visible parcel timeline can read `shipments.events` as the seller types them (labelled "from the seller").
- **W7 (operator):** a store sent back or suspended is shown to its owner with the operator's reason on the status page and in a strip on every seller page; a rejected listing shows `products.rejected_reason` and the seller can send it again (`seller.products.resubmit`, status back to `pending_approval`).
- **Shared files touched:** `app/Services/Cart/CartQuote.php` and `app/Services/CheckoutService.php` (one `PromoRules` check each), `app/Models/OrderItem.php` (a parcel for every line), `app/Support/Navigation.php` (two more seller links), `app/Support/Status.php` (two states), `resources/views/components/desk-shell.blade.php` and `resources/views/layouts/partials/head.blade.php` (decode a slot once before printing: names with `&`).
