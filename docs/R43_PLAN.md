# R-43 Dot.Emall: plan and decisions

Inputs: `R43_UX_AUDIT.md` (201 captures, 30 problems), `R43_BACKEND_AUDIT.md` (commerce engineering, when merged), `R43_RESEARCH.md` (competitive and South African commerce research), `R43_DEMO.md` (demo world), the owner brief and order (build everything, no questions, deploy when done). Written 2026-11-09. The owner is not available during the build, so every product decision below was taken on the safest, most reversible basis and is recorded in `docs/R43_DECISIONS.md`.

## Position

Emall already has a sound marketplace core: stores, products, cart, orders with price snapshots and idempotency keys, Paystack payments with a webhook handler and event log, refunds, promo codes, reviews with ratings aggregates, a seller desk, operator moderation. What it lacks is everything that makes it a destination: a public storefront (the product pages sit inside the seller back-office), categories, discovery, variants, delivery, tracking, returns, an address book, wishlists, order e-mail, guest checkout, and a coherent design. The job is to add these on the existing architecture, with honest data, and to make the whole thing feel like one product.

## Decisions taken (summary; reasoning in R43_DECISIONS.md)

1. **Identity.** Accounts come from InfoDot only (registration stays closed). "Create account" and "Open a store" CTAs become "Sign in with InfoDot" with one honest sentence of explanation. **Shopping does not require an account**: guests browse, keep a cart and check out with an e-mail address; they follow the order through a signed magic link; when someone later signs in through InfoDot with the same verified e-mail their guest orders attach to the account.
2. **Total price first.** Product cards and pages show price; the product page and cart show delivery cost and VAT-inclusive total for the chosen delivery address or a region estimate, stated as such. Prices are VAT inclusive as displayed (South African consumer norm); VAT shown as a line where the seller is VAT registered (new store field, default off, nothing invented).
3. **No false urgency, no fake references.** A "was" price is shown only from real recorded price history (lowest price in the previous 30 days), never from a seller-typed "original price"; no countdown timers, no "N people viewing", no invented scarcity; low stock is shown only as the real remaining quantity when at most 5.
4. **Search** runs through one `SearchEngine` interface: PostgreSQL full-text + trigram + unaccent (when the extensions are available on the host) with a portable LIKE/token scorer used on SQLite and as a fallback, plus a synonyms table, typo tolerance, autocomplete, facets from structured product attributes, filters (category, brand, price, availability, rating, seller, region) and sorts. Search never fabricates relevance.
5. **Recommendations** come only from real data: co-purchase counts from paid orders and co-view counts, recently viewed, same category and brand; each block says what it is based on and the number behind it; below a minimum count it is not shown.
6. **Variants** (options such as size/colour, each variant with SKU, price, stock) and **structured attributes** (spec facets) are added to the schema; existing products keep working as single-variant products.
7. **Delivery.** Per-store shipping methods (flat rate, free over a threshold, collection/pickup) with per-region rules; checkout splits the cart into seller groups with their own delivery cost and delivery estimate (seller-set handling time + method window, labelled an estimate); no carrier API integration is claimed.
8. **Tracking** is whatever the seller enters (carrier, tracking number, status events) shown as "from the seller"; nothing is ever invented. Order e-mails and in-app notices are sent for real events.
9. **Returns** follow South African law as an adopted standard, not a legal claim: a return request within 7 days of delivery (ECTA) per order item, seller responds (approve/decline with reason, decline needs a stated reason), approved returns feed the existing refund service; disputes the seller does not answer in 10 days are escalated to the operator queue.
10. **Money ledger.** Seller earnings are recorded in an internal ledger per seller group (gross, platform commission from config with the full fee shown to the seller before publishing, net), released after delivery plus the 7-day window; no automated payouts or Paystack transfers are built (owner item: confirm ZAR transfers and whether holding funds is regulated).
11. **Trust ladder** shown on stores, from real data only: signed in via InfoDot (checked), InfoDot company linked (checked, if an InfoDot team is linked), claimed registration/VAT numbers (claimed, labelled), fulfilment rate, response time, return rate, review count; never a "verified seller" badge without InfoDot data that actually says so.
12. **Reviews.** Verified purchase only when the buyer has a delivered order item for that product; photos; helpfulness votes; one seller response; report button feeding the operator queue; Q&A on product pages; no seller control over deletion.
13. **Wishlist, saved searches, alerts.** Wishlist; saved searches; back-in-stock and price-drop alerts (price drop only against real recorded history); recently viewed.
14. **Shopping assistant.** A conversational helper that turns a request such as "a reliable laptop for university under R15,000" into structured filters and a short list with the facts it used (price, stock, specs, total cost); deterministic rules by default, a model only if a key is configured, always labelled, never inventing product facts.
15. **Design** is one system for storefront, account, seller and operator areas, self-hosted, light and dark, mobile first; product imagery leads; no Amazon grid clone.
16. **Ecosystem.** Real: InfoDot sign-in and team link; Dot.Notify events where the client exists; Dot.Analytics aggregate publisher (inert without env); Dot.Files not used unless an API exists; Pulse/Brain/Memory/Engage/Billing only where a real API is documented, otherwise listed as not integrated.

## Workstreams

| # | Workstream | Files |
|---|---|---|
| W1 | Design system and shell (storefront, account, seller, operator layouts), kit, Jetstream/auth/errors/mail/welcome | shell agent |
| W2 | Schema and services: variants, attributes, addresses, shipping methods, shipments/events, returns, wishlist, saved searches, alerts, price history, Q&A, seller ledger, categories seeder, guest orders | data agent |
| W3 | Discovery: homepage, categories, search, autocomplete, facets, collections, recommendations, assistant | discovery agent |
| W4 | Product page, store pages, reviews and Q&A | product agent |
| W5 | Cart, checkout (guest + account), payment return, orders, tracking, returns | checkout agent |
| W6 | Seller: onboarding, storefront, products/variants/inventory, shipping, orders, returns, promotions, analytics, ledger | seller agent |
| W7 | Trust and operator: moderation queues, reports, disputes, seller trust ladder, platform metrics | trust agent |
| W8 | QA end to end (two agents), security/performance, outside design juries, polish | later |

## Principles for every change

Server-side prices always; transactions around order creation and stock; idempotent payment handling; nothing marked paid from the browser; no fabricated data; every control real; mobile first; accessibility; PostgreSQL parity tested; no registrations in route files (route:cache lesson); run the deploy workflow's inline checks locally before dispatching.
