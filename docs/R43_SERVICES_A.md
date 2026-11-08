# R-43 data agent A: commerce services for the UI agents

Branch `feat/r43-da`. Everything here is a service, model, policy, command or config: **no view, CSS or JS was written or touched**. Decisions D-14 to D-27 are in `R43_DECISIONS.md`. Every service is resolved from the container (`app(Class::class)`), is covered by `tests/Feature/Commerce/*`, and every money amount is **integer cents** unless a model column says `decimal`.

Conventions the services keep, so a view never has to:

- Prices, delivery, VAT and totals are computed on the server. A form field is a *request* ("I want method 4", "I was shown R280.00"), never a number to believe.
- A user-facing failure is an exception with a sentence a buyer can read: `CheckoutException` (and its children `PriceChangedException`, `OutOfStock`, `PromoRejectedException`), `CartProblem` (has `->field`), `Illuminate\Validation\ValidationException` (field-keyed), `InvalidArgumentException` (a refused state change), `AuthorizationException`.
- "Estimate", "claimed", "from the seller" are labels the data carries (`estimateLabel`, rung `state: claimed`, `Shipment` fields); render them, do not drop them.

---

## 1. Categories (`App\Services\Catalogue\CategoryTree`)

`StoreCategory` is now a tree: `parent_id`, `sort_order`, `depth`, `path` (`/1/5/9/`), `slug`, `description`, `image_path`, `is_active`; relations `parent`, `children` (ordered), `products`, `attributeDefinitions`; `subtreeIds()`; scope `withDescendantsOf($root)`.

```php
$tree->create(array $data, ?StoreCategory $parent = null): StoreCategory   // unique slug guaranteed
$tree->move(StoreCategory $c, ?StoreCategory $newParent): StoreCategory    // rewrites paths below; refuses a cycle (InvalidArgumentException)
$tree->breadcrumbs(StoreCategory $c): StoreCategory[]                      // root ... c
$tree->tree(bool $onlyActive = true): array   // nested: id,name,slug,depth,image_path,direct_count,rollup_count,children[]; 2 queries
$tree->counts(): array                        // [category_id => ['direct'=>n,'rollup'=>m]], LISTED products only (Product::browsable()), cached 60 s
CategoryTree::forgetCounts()                  // called automatically on product create/delete/status/active/category/store change
```

Seed: `php artisan emall:seed-categories [--without-attributes]`: ~22 roots and ~110 children (electronics and computers, phones, home and kitchen, appliances, furniture, garden and outdoor, DIY and tools, fashion women/men/kids, beauty, health, baby, toys and games, sports and outdoor, automotive, books and stationery, pets, groceries and pantry, office and school, art and crafts, farm and agri supplies). Idempotent by slug, never deletes or renames a category an operator added, **never runs automatically**. Taxonomy data lives in `Taxonomy::categories()` / `attributes()`.

## 2. Variants, options and stock (`Catalogue\ProductVariants`, `Inventory`, `VariantSync`)

Models: `ProductVariant` (`options` JSON, `option_key`, `price`, `stock`, `stock_version`, `is_active`, `is_default`, `sku`, `image_id`; `label()` = "Size: M, Colour: Red" or null; `priceCents()`), `ProductOption` (`name`, ordered `values`), `StockMovement` (append-only: `delta`, `stock_before`, `stock_after`, `reason`, `actor_id`, `order_id`, `order_item_id`). `Product` gains `variants()`, `defaultVariant()`, `options()`, `brand()`, `attributeValues()`, `low_stock_threshold`.

```php
$variants->defineOptions(Product $p, ['Size' => ['S','M','L'], 'Colour' => ['Red','Blue']]): void   // max 3 options, 50 values
$variants->saveVariant(Product $p, ['Size'=>'M','Colour'=>'Red'], ['price'=>120, 'stock'=>5, 'sku'=>..., 'is_active'=>..., 'image_id'=>...]): ProductVariant
         // choices must name every option; same choices again = update (only keys you pass change); first real variant retires the implicit one
$variants->remove(ProductVariant $v): void        // deactivates if ever ordered/default, else deletes
$inventory->setStock(ProductVariant $v, int $typed, ?int $seen, ?int $actorId): int   // the seller's edit as a DELTA (typed - seen) on today's stock
$inventory->lowStock(Store $s, int $limit = 100): Collection<ProductVariant>           // at/below the PRODUCT's own threshold
// take()/give() are used by checkout and the lifecycle; call them only inside a transaction that holds the locks (products by id, then variants by id)
```

Rules to keep in the UI: show `Product::price` as "from" when the product has more than one active variant; stock on the product row is the sum of active variants. The seller edit form should post the hidden **`stock_was`** (the stock number the form was drawn with); `SellerProductController::update` prefers it over the session. A product with options takes price/stock per variant: the product form's price/stock are ignored for it.

Cart: add with `variant_id` (`POST cart.store`: `product_id`, `variant_id`, `quantity`); a product with several active variants and no `variant_id` answers `variant_id` error.

Seller low-stock alert: when a sale takes a variant from above to at-or-below the threshold the seller gets a `low_stock` notice once.

## 3. Attributes and brands (`Catalogue\ProductAttributes`, `Brand`)

```php
$attrs->definitionsFor(?StoreCategory $c): Collection<string,CategoryAttribute>   // own + ancestors' (a child may override a key)
$attrs->sync(Product $p, ['ram'=>'16','operating_system'=>'Windows']): Collection<ProductAttribute>   // validates type/enum/required; replaces the set; ValidationException keyed "attributes.<key>"
$attrs->facetCounts(array $productIds, ?array $keys = null): array   // ['ram'=>['16'=>2,'8'=>1]] most common first, one query
Brand::named('samsung'): Brand   // case-insensitive find-or-create; products.brand_id
```
`CategoryAttribute`: `key,label,type(text|number|enum|boolean),unit,options,is_facetable,is_required,position`. `ProductAttribute` keeps `value` (display) and `value_number` (range filters), `unit`, `is_facetable`. Seeded definitions: laptops (ram, storage, storage type, processor, screen size, OS), desktops, tablets, monitors, smartphones, TV, fridges, washing machines, clothing men/women/kids (size, colour, material), shoes, furniture, books, groceries, power tools, solar.

## 4. Price history and "was" price

`ProductVariant` events write `product_price_history` (variant, old, new, actor, `changed_at`) on creation and on every price change. Recording started the day the migration ran: **nothing is invented for the past**.

```php
ReferencePrice::for(ProductVariant|Product $x): ?array   // ['cents'=>int,'since'=>Carbon,'window_days'=>30] or null
```
Non-null only when the variant's history **starts at least 30 days ago**, the lowest price that was in effect at any moment of the 30 days before today's price took effect is **higher than today's**, and the latest record matches the live price. Never reads `compare_price`. Label it "lowest price in the last 30 days" and show the date range (`since` to now).

## 5. Addresses (`Addresses\AddressBook`, `AddressRules`, `AddressPolicy`)

```php
$book->add(User $u, array $fields): Address        // first address = default shipping; max 20
$book->update(Address $a, array $fields): Address
$book->delete(Address $a): void                    // hands the default on
$book->setDefault(Address $a, 'shipping'|'billing'): Address
$book->defaultShipping(User $u): ?Address
$book->validated(array $fields): array             // for guests / one-off addresses
AddressRules::for($input, $prefix = ''): array     // province must be one of the nine and a 4-digit postal code for ZA; generic for other countries
AddressRules::PROVINCES
```
Fields: `label, recipient_name, phone, line1, line2, suburb, city, province, postal_code, country (ISO2), instructions, is_default_shipping, is_default_billing`. Orders store **`shipping_snapshot`** (JSON, immutable) *and* the legacy `shipping_address` one-liner. Checkout accepts `address_id` (a saved address of the buyer), `address[...]` (fields), or the old `shipping_address` string.

## 6. Shipping, totals, VAT (`Shipping\ShippingQuote`, `Cart\CartQuote`, `Support\Vat`)

Models: `ShippingMethod` (`type` flat|free_over|collection, `price`, `free_over`, `handling_days`, `window_min_days`, `window_max_days`, `collection_address`, `instructions`, `is_active`, `regions()`), `ShippingMethodRegion` (`country, province, postal_from, postal_to, price`). Policy `ShippingMethodPolicy` (create/update/delete = the store's owner). Store fields `vat_registered`, `vat_number` (claimed).

```php
ShippingQuote::for(Collection<CartLine>|array<storeId,itemsCents> $cartOrGroups, Address|Region|array|null $to, ?Carbon $today = null): array<storeId, GroupQuote>
GroupQuote { storeId, storeName, itemsCents, options: ShippingOption[] (cheapest first), cheapest(), option(?int $methodId), canDeliver() }
ShippingOption { methodId, name, type, priceCents, freeApplied, handlingDays, windowMinDays, windowMaxDays, estimatedFrom, estimatedTo,
                 estimateLabel ("Estimated delivery 12-16 Nov (an estimate ... not a promise)"), instructions, collectionAddress,
                 freeOverCents, isCollection(), isArranged(), remainingForFree($itemsCents) }
$cartQuote->quote(Collection<CartLine> $lines, ?Region $region, ?string $promoCode, array $methodChoices = [storeId => methodId], ?Carbon $today): QuoteResult
QuoteResult { groups[storeId]{store_name, lines, items_cents, discount_cents, options, selected, delivery_cents, vat_registered, vat_cents, total_cents, remaining_for_free_cents, can_deliver},
              itemsCents, discountCents, deliveryCents, vatCents, totalCents, promo, promoProblem, problems[variantId=>reason], currency, canCheckout() }
```
A method applies with no region rules, or when one matches (country, then province, then an inclusive postal range; the most specific rule wins and may carry its own price). With an unknown destination only rule-free methods apply. Collection always applies. No method at all = "arranged with the seller" (D-16). Dates use working days.

**Checkout inputs** (`CheckoutService::place` / `CartController::checkout`): `shipping_methods[storeId]=methodId` (absent = cheapest; a method the store does not offer for that address is refused), `expected_total_cents` (the `QuoteResult->totalCents` the page showed). A different recomputed total throws **`PriceChangedException`** (`expectedCents`, `actualCents`, a readable message) and nothing is placed: render the message, redraw the cart from a fresh `CartQuote`. VAT is the part already inside the price for VAT-registered stores only (`Vat::includedIn`); it is never added on top. A `PromoCode` may carry `free_shipping` (`PromoCodeController` accepts it).

Order shape: `orders.delivery_total`, `orders.vat_total`, `orders.promo_free_shipping`; `OrderGroup` per store (`order->groups`): `method_name, method_type, delivery_cents, vat_cents, handling_days, estimated_from/to, status, shipped_at, delivered_at`; `OrderItem->order_group_id`. Order total = subtotal - discount + delivery of every parcel that still has a live line.

## 7. Guests (`Cart\CartManager`, `Guests\GuestOrderAccess`, `Guests\GuestOrders`, `CheckoutService::placeAsGuest`)

```php
$cart->lines(?User $u): Collection<CartLine>        // null = the session cart; CartLine{variant, product, quantity, cartItemId, unitCents(), totalCents(), storeId(), problem()}
$cart->add(?User, ProductVariant, int $qty)         // CartProblem (->field 'quantity' | 'product_id') on stock/own-listing/unavailable
$cart->setQuantity(?User, int $variantId, int $qty); $cart->remove(?User, int $variantId); $cart->clear(?User); $cart->count(?User)
$cart->mergeGuestInto(User $u): int                 // runs automatically on every sign-in (Login event listener OnSignIn)
$checkout->placeAsGuest(string $email, array $lines /*[['variant_id'=>,'quantity'=>]]*/, array $input, ?string $token, ?string $ip): Order
         // then clear the session cart yourself ($cart->clear(null)); replays with the same token return the same order
$access = app(GuestOrderAccess::class);
$access->url(Order): ?string      // the magic link (also in every guest e-mail); tokenFor(), verify(), rotate(), revoke(), grantSession(), hasSessionGrant()
app(OrderAccess::class)->presentGuest(Order): array   // same shape as present(): the buyer's view of ONE order; role OrderAccess::GUEST
```
Routes registered in the `// --- data-a ---` block of `routes/web.php`: `GET /guest/orders/{order}/{token}` (`guest.orders.access`) and `GET /guest/orders/{order}/{token}/payment` (`guest.orders.payment.callback`, where Paystack sends a guest back; it verifies with Paystack and believes nothing in the URL). Both answer **404 for anything wrong** (bad/rotated/expired token, account-owned order, unknown id), are throttled by `guest-order` (defined in `EmallServiceProvider`), store a session grant for that one order, and redirect to the named route **`guest.orders.show` if the checkout agent registers it**, otherwise return a small JSON summary. The guest checkout form needs the existing `throttle:checkout` limiter on its POST. Guests: `orders.guest_email`, `user_id` null; the e-mail is lower-cased. A guest's lifecycle actions use `OrderLifecycle::confirmReceipt($line, null)`, `cancelItem($line, null, $note, 'guest')` and `ReturnService::request($line, null, ...)` **after** the controller has verified the order link (`GuestOrderAccess::hasSessionGrant`).

## 8. Checkout hardening

- D-1: `config('emall.checkout.requires_gateway')` (true in production) refuses `place()`/`placeAsGuest()` with "Payments are not open yet" unless Paystack is usable. Replays of an already-placed order are returned before the gate.
- D-4: `EMALL_STOCK_HOLD_MINUTES` (30), extended while an attempt is open, capped at twice the window; at most `EMALL_MAX_UNPAID_ORDERS` (3) unpaid payment-required orders per buyer or guest e-mail ("You already have N orders waiting for payment").
- `expected_total_cents` price-drift check (section 6); seller `stock_was` (section 2).
- `orders:expire-unpaid` (existing command, every 10 minutes, overlap lock 15 min) is idempotent: one restock, one `expiry` stock movement, buyer or guest told once; the cancelled order no longer reads payment `pending`.
- Sellers cannot move a line of an order that requires payment until it is paid (packing, shipped and ready-for-collection all refuse).

## 9. Order state machine (`OrderLifecycle`, `OrderStatus`, `OrderStatusHistory`)

States (line and order): `pending, processing ("Packing"), shipped, ready_for_collection, delivered, collected, cancelled, refunded`. `OrderStatus::sellerTransitions()/buyerTransitions()/systemTransitions()` list the legal moves; `isCompleted()`, `completedValues()`, `label()`.

```php
$life->advanceItem(OrderItem $line, OrderStatus $to, ?User $actor = null, ?string $note = null, string $role = 'seller', bool $notify = true): OrderItem
$life->cancelItem(OrderItem $line, ?User $actor = null, ?string $note = null, ?string $role = null): OrderItem   // restocks, re-prices, reverses the ledger, refunds if paid
$life->confirmReceipt(OrderItem $line, ?User $buyer = null): OrderItem   // shipped->delivered or ready->collected; null buyer = a guest
$life->markRefunded(OrderItem $line, ?User $actor, string $note): OrderItem   // system only, after a refunded return
$life->allowedFrom(OrderStatus $from, string $role): OrderStatus[]   // 'seller'|'buyer'|'guest'|'operator'|'system'
```
Each move writes a timestamp on the line (`processing_at, shipped_at, ready_at, delivered_at, collected_at, cancelled_at, refunded_at`), a row in `order_status_histories` (`from_status,to_status,actor_id,actor_role,note`; `Order::statusHistory()`, `OrderItem::statusHistory()`), keeps the parcel and the order's derived status current, and when a whole parcel is delivered or collected starts the ledger release clock. `OrderAccess::present()` returns `groups` (with `shipments.events`), `history` and `returns` filtered to the viewer (a seller sees only their own parcel and lines). `SellerOrderController::updateItemStatus` now passes the actor and the lifecycle notifies the buyer (no double notice).

## 10. Shipments and tracking (`Shipping\ShipmentService`, `Carriers`, `ShipmentPolicy`)

```php
$ships->ship(OrderGroup $g, User $seller, ['carrier_key'?, 'carrier_name'?, 'tracking_number'?, 'estimated_from'?, 'estimated_to'?, 'note'?, 'order_item_ids'?[]]): Shipment
$ships->updateTracking(Shipment, User $seller, array $data): Shipment
$ships->addEvent(Shipment, User $seller, string $description, ?string $location = null, ?Carbon $at = null): ShipmentEvent
app(Carriers::class)->options(): array<key,name>;  ->trackingUrl(?string $key, ?string $number): ?string
```
`ship()` moves the lines to shipped through the state machine (an unpaid order, a collection parcel or a cancelled line is refused there), may ship part of a parcel, and sends the buyer ONE notice with the carrier and tracking number. A tracking **link** exists only for an allowlisted carrier (`config('emall.shipping.carriers')`) and a number that matches its pattern; anything else is plain text. `Shipment`: `carrier_key, carrier_name, tracking_number, tracking_url, shipped_at, estimated_from/to, note, items(), events()`. Label all of it "from the seller"; `ShipmentEvent` is text the seller typed.

## 11. Returns (`Returns\ReturnService`, `ReturnRequestPolicy`, enums `ReturnStatus`, `ReturnReason`)

```php
$returns->ineligibleBecause(OrderItem $line): ?string          // null = can be requested now (delivered/collected, within 7 days, none open)
$returns->request(OrderItem $line, ?User $buyer, string $reason, ?string $note, array $photoPaths = []): ReturnRequest   // null buyer = guest
$returns->approve(ReturnRequest, User $seller, ?string $note); ->decline(ReturnRequest, User $seller, string $reason /*mandatory*/)
$returns->markReceived(ReturnRequest, User $seller, bool $restock = false)   // refunds (RefundService), reverses the ledger, line -> refunded, optional restock
$returns->escalate(ReturnRequest, User $buyer, ?string $note)                // after a decline
$returns->operatorQueue(): Builder  /  forSeller(User): Builder  /  operatorResolve(ReturnRequest, User $operator, 'approve'|'uphold_decline', string $note)
$returns->escalateOverdue(): int                                              // scheduled
```
Reasons: `faulty, not_as_described, wrong_item` (seller pays the return: `ReturnReason::sellerPaysReturn()`), `changed_mind, other` (`other` needs words). Window `EMALL_RETURN_WINDOW_DAYS` (7), seller deadline `EMALL_RETURN_RESPONSE_DAYS` (10). `emall:escalate-returns` runs hourly (`withoutOverlapping(30)`), idempotent. Refund = the line after its promo share (not delivery), `Refund.kind = 'return'`, asked of Paystack after commit; orders that never took payment record no refund. Photos: paths only (D-25).

## 12. Seller ledger, fees, reconciliation (`Ledger\LedgerService`, `SellerFees`, `Reconciler`)

```php
$ledger->commissionPercent(?int $storeId): float    // per-store override (StoreCommissionOverride) else config('emall.commission.percent') (default 0)
$ledger->balance(User|int $seller): array           // ['ZAR' => ['held'=>cents,'released'=>cents,'commission'=>cents]] with reversals netted
$ledger->releaseDue(?Carbon $now): array            // the hourly job: ['released','skipped_open_returns','net_cents']
SellerFees::text(?Store $s): string  /  app(SellerFees::class)->disclosure($store) / ->percent($store)   // the fee sentence to show BEFORE publishing
```
Commands: `emall:release-ledger` (hourly, `withoutOverlapping(30)`), `emall:reconcile [--days=N]` (exit 1 and a critical log on any mismatch; `Reconciler::run()` returns `{checked, mismatches[]}`). Entries (`LedgerEntry`): `kind sale|reversal`, `status held|released|reversed`, `gross_cents, commission_cents, net_cents, commission_percent, release_at, released_at, reason`, per `order_group_id`. **No payout transfer exists.**

## 13. Notifications (`Notifications\OrderNotifier`, `NotificationPreferences`, `CommerceNotification`)

Every real event sends a database + mail notice through Laravel notifications: order placed, paid, payment failed, packing, shipped (carrier and tracking), ready for collection, delivered, collected, refund recorded, return requested/approved/declined/received/escalated, ledger released (seller), low stock (seller), new order (seller, existing), line cancelled (existing). Guests get the mail only, with their order link. `EmallNotification` is the base (database, mail, optional Dot.Notify). Copy for each kind is one `match` in `CommerceNotification::copy()`.

```php
$prefs->channels($notifiable, 'transactional'|'alerts'|'marketing'): string[]   $prefs->set(User, $category, bool $mail, bool $inApp)   $prefs->all(User)
```
Transactional is locked on; marketing is off everywhere by default. Notices to people go through `app(OrderNotifier::class)->buyer($order)` (user or on-demand mail notifiable). Dot.Notify (`Services\Notify\NotifyClient`) is inert unless `NOTIFY_INGEST_URL` and `NOTIFY_INGEST_SECRET` are set.

## 14. Seller onboarding and trust (`Stores\StoreOnboarding`, `Stores\StoreTrust`)

Store columns: `status` (draft|pending_review|active|suspended, enum `StoreStatus`), `status_reason`, `submitted_at, reviewed_at, reviewed_by`, `legal_name, contact_email, contact_phone, region, city, return_policy, shipping_policy, vat_registered, vat_number, registration_number, banner_path, team_id`.

```php
$onb->rules(): array   $onb->create(User, array)  $onb->update(Store, array)  $onb->missingForSubmission(Store): array  $onb->submit(Store)
$onb->approve(Store, User $operator)  ->reject(Store, User $operator, string $reason)  ->suspend(Store, User $operator, string $reason)  ->reinstate(Store, User $operator)
$onb->linkTeam(Store, Team)  $onb->reviewQueue()
StoreTrust::for(Store $s): ['ladder' => [['key','label','state' yes|claimed|no,'source','detail']...], 'metrics' => [fulfilment_rate, median_hours_to_ship, return_rate, review_count, review_average => ['value','sample','note','unit']], 'verified' => bool, 'months_on_emall' => int]
```
`StoreController` now goes through `StoreOnboarding` (a new store is live as before unless `EMALL_SELLER_REQUIRES_REVIEW=true`). The ladder never shows a verified state unless InfoDot says so.

## 15. Performance

New indexes are named in the migrations; `tests/Feature/Commerce/IndexesPostgresTest` proves 20 query paths can use an index (sequential scans switched off), and `QueryCountTest` proves the read methods of the cart, quote, shipping, category tree, order page data, ledger balance, returns lists and trust ladder cost the same number of queries for 2 rows as for 20. EXPLAIN on a synthetic PostgreSQL set (20,000 products, 40,000 orders/parcels/ledger entries/lines, 100,000 stock movements, 60,000 price records, 20,000 attributes, 20,000 return requests):

| Path | Plan | Execution |
|---|---|---|
| stock movements of a variant | index scan `stock_movements_variant_index` | 0.16 ms |
| price history of a variant | `price_history_variant_time_index` | 0.10 ms |
| category subtree by path prefix | `store_categories_path_index` (`varchar_pattern_ops`, needed for LIKE prefix) | 0.08 ms |
| category rollup counts (cached 60 s) | one grouped scan of listed products | 10 ms at 20k products |
| facet counts for a category | `products_category_status_active_index` + `product_attributes_product_key_unique` | 0.47 ms |
| returns due for escalation | `return_requests_status_due_index` | 0.81 ms (400 rows) |
| a seller's ledger balance | `ledger_entries_seller_status_index` | 0.37 ms |
| a seller's incoming returns | `return_requests_seller_status_index` | 0.08 ms |
| a seller's parcels by status | `order_groups_seller_status_index` | 0.14 ms |
| shipment by tracking number | `shipments_tracking_index` | 0.03 ms |
| guest orders of an address | `orders_guest_email_index` (stored lower-case, exact match) | 0.01 ms |

## 16. Config, env, commands, schedule

`config/emall.php` (checkout gate and hold, commission, VAT rate, shipping carriers/`require_methods`, guest token days, return windows, seller review, Dot.Notify, reference-price window). Env: `EMALL_CHECKOUT_REQUIRES_GATEWAY`, `EMALL_STOCK_HOLD_MINUTES`, `EMALL_MAX_UNPAID_ORDERS`, `EMALL_COMMISSION_PERCENT`, `EMALL_VAT_RATE`, `EMALL_LEDGER_RELEASE_DAYS`, `EMALL_GUEST_TOKEN_DAYS`, `EMALL_RETURN_WINDOW_DAYS`, `EMALL_RETURN_RESPONSE_DAYS`, `EMALL_SHIPPING_REQUIRE_METHODS`, `EMALL_SELLER_REQUIRES_REVIEW`, `NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET`, `NOTIFY_PLATFORM_KEY`.

Commands: `emall:seed-categories`, `emall:release-ledger` (hourly), `emall:escalate-returns` (hourly), `emall:reconcile`; existing `orders:expire-unpaid`. Limiters `guest-order` and `guest-cart` are in `App\Providers\EmallServiceProvider` (not in a routes file).

## 17. What the UI agents must wire (nothing below is built in a view)

1. Product page: variant picker posting `variant_id`; show `ReferencePrice::for()` only when non-null; show `StoreTrust::for($store)`; low stock only as the real number when at most 5.
2. Cart/checkout: call `CartQuote` for the shown total and post `expected_total_cents`, `shipping_methods[storeId]`, and `address_id` or `address[...]`; catch `PriceChangedException`/`CheckoutException` and redraw. Guests: `CartManager` with a null user, `placeAsGuest`, then `$cart->clear(null)`; register `guest.orders.show` and use `presentGuest`.
3. Seller edit form: hidden `stock_was`; per-variant price/stock/SKU through `ProductVariants`; `low_stock_threshold`; the fee sentence `SellerFees::text($store)` next to publish; shipping methods and regions (policy `ShippingMethodPolicy`); ship form calling `ShipmentService`.
4. Order pages: render `present()['groups'|'history'|'returns']`, the estimate labels and "from the seller" on tracking; buyer buttons for `confirmReceipt`, return request and escalate.
5. Operator: `ReturnService::operatorQueue()`, `StoreOnboarding::reviewQueue()`, store approve/reject/suspend with a reason; payments desk unchanged.
6. Views that still read `$item->product->price` show a variant line's cheapest price; the cart row should read `$item->variant->price`.
