# R-43 data agent B: discovery and engagement services

Everything below is service code with tests. There are no views. Feature agents call these classes; none of them needs to know which database is under it. Real data only: every number a service returns can be traced to rows, and a service that has nothing honest to say returns an empty result, not filler.

Branch `feat/r43-db`. Decisions D-30 to D-41 in `R43_DECISIONS.md`. Config in `config/discovery.php`.

## 1. Seams to the commerce data workstream (closed by the integration pass, D-50 to D-53)

| Contract | Implementation | Purpose |
|---|---|---|
| `App\Contracts\CatalogueSource` | `Services\Discovery\DatabaseCatalogueSource` (the real tables) | `brands(ids)`, `attributes(ids)`, `variantOptions(ids)` (option values of active variants), `storeRegions(storeIds)`, `deliveryEstimate(id)` (the store's cheapest delivery method, working days). Batch shaped. Tests bind `Tests\Support\FakeCatalogue`. There is no price history here: there is one, `Catalogue\PriceHistory`. |
| `App\Contracts\CategoryTree` | `Services\Catalogue\CategoryTree` (data A's tree) | `withDescendants`, `all`, `find`, `findBySlug`, `many`, `path`, `forget`. One tree for the whole app. |
| notification preferences | `Services\Notifications\NotificationPreferences` (data A's table) | Alerts, saved-search digests and "answered" notices are in the **alerts** category: in the app by default, e-mail opt-in. B's own contract and allow-all default were removed. |

## 2. Search

```php
$search = app(App\Services\Search\SearchService::class);          // engine + log + funnel event
$result = $search->search(SearchRequest::fromArray($request->query()), Actor::forRequest($request));
// or app(SearchEngine::class)->search($request) for a side-effect-free search
```

`SearchRequest::fromArray` reads `q`, `category` (id or slug, includes descendants), `brand[]`, `price_min`, `price_max`, `in_stock`, `rating` (1-5), `store[]`, `region[]`, `attr[key][]`, `sort`, `page`, `per_page`, `literal=1` (turn interpretation off). Sorts: `relevance`, `price_asc`, `price_desc`, `newest`, `rating`, `best_selling`.

`SearchResult` (readonly): `items` (list of `SearchHit`: `product` with store and images loaded, `score`, `matched` fields, `notes`, `brand`, `inStock`, `why()` = "Matched title and brand. "notebook" counts as "laptop""), `total`, `page`, `perPage`, `lastPage()`, `facets`, `didYouMean`, `correctedFrom`, `relaxed`, `appliedFilters` (chips: `key`, `label`, `value`, `inferred`; remove with `SearchRequest::withoutFilter($key)`), `understood`, `engine`, `sort`, `sortNote`, `notes` (sentences to show: "Showing results for ...", "Nothing matched all of your words ..."), `relaxations` (when empty: which filter to drop for how many results).

Facets (all counts real; each group excludes its own filter): `categories`, `brands`, `price` (`min`, `max`, `buckets` computed from the real range in steps of 1, 2, 2.5 or 5 x 10^n), `availability`, `rating` (4+, 3+), `stores`, `regions`, `attributes` (only when a category is selected; keyed by attribute key, up to 6 keys by coverage).

Engines: `PostgresSearch` (weighted tsvector, prefix `to_tsquery`, `ts_rank_cd`) and `PortableSearch` (word-prefix LIKE with a scored order, same result shape). `SearchCapabilities` picks one (cached an hour): PostgreSQL with the generated `search_vector` column gets `PostgresSearch`, anything else `PortableSearch`. `pg_trgm` is used for typo candidates when installed. Ranking: text rank, exact title +6, title prefix +3, in stock +1.5, rating bonus **only** with at least 3 reviews. Never paid placement.

Retry ladder (stops at the first rung that finds something): meaning read out of the text; the same words literally (inferred filters never hide results); misspelt words corrected against the catalogue vocabulary; any of the words (flagged `relaxed`).

`QueryUnderstanding::parse($text)` returns a `ParsedQuery` (`inferred`, `detected`, `slots`). Reads prices ("under R15,000", "below 5k", "between R2,000 and R5,000", "R1000-R2000", "over R500"), "in stock", brand and category names from the catalogue (categories also through synonyms), "55 inch" / "256gb" only when a listing really has that value, "size 42", a colour only when a listing has it. `literal($text)` applies synonyms only.

Synonyms: table `search_synonyms` (groups of equivalent words, edited as `App\Models\SearchSynonym`), seeded by migration with 25 groups including South African usage (takkies, braai, bakkie, geyser, cooldrink). `DefaultSynonyms::seed()` only adds missing rows.

### Autocomplete
`app(Suggest::class)->for($typed, $actor)` returns `products` (`id`, `title`, `price`, `image_id`, `store`, `in_stock`), `categories`, `brands` (with live counts), `recent` (the person's own), `popular` (3+ different people, results found). JSON: `GET /search/suggest?q=` (`route('search.suggest')`, throttle `suggest`).

### Search history
`SearchLog`: `record`, `recentFor`, `popular`, `zeroResults($days, $limit)` (operator report), `prune`. Table `search_queries(query, result_count, actor_hash, created_at)`.

### Index
Tables `search_documents`, `search_document_attributes`, `search_terms`. Kept current by `SearchIndexObserver` on Product, Store and StoreCategory (inline after commit for up to 25 listings, queued above that), by `emall:search-reindex --stale` every five minutes, and rebuilt by `php artisan emall:search-reindex`. Visibility, price, stock and rating are read live, never indexed. `--status` prints the engine and capabilities. `SearchIndexer::index($ids)` is the call for other workstreams after changing brands or attributes.

## 3. Behaviour

| Service | Calls |
|---|---|
| `Discovery\RecentlyViewed` | `view($actor, $product, $source)` (records the list and the funnel event; a seller viewing their own listing is not a view), `for($actor, $limit, $excluding)`, `merge($token, $userId)` (done automatically by the `MergeAnonymousActivity` listener on `Login`), `forget`, `prune`. 20 per person. |
| `Discovery\Track` | `search`, `productView($actor, $product, $source)` (`search`, `category`, `recommendation`, `collection`, `homepage`, `wishlist`, `direct`), `addToCart`, `checkoutStarted`, `wishlistAdd`, `orderPaid` (written by the `RecordOrderPaid` listener, once per order), `record`. Never throws. |
| `Support\Actor` | `forRequest($request)` (user, else a session token created on first use, else `none()`), `forUser`, `forToken`, `hash()`. |
| `Discovery\Recommender` | `forProduct($product, $actor)` (only blocks with something to say), `boughtTogether`, `viewedTogether`, `complementary`, `sameCategoryAndBrand`, `recentlyViewed`, `becauseYouViewed`, `becauseYouBought`. Each returns a `RecommendationSet` (`key`, `title`, `basis`, `editorial`, `items` of `RecommendedItem(product, basis, support)`) or null. The basis carries the real number ("Bought together in 5 orders"). Out-of-stock, unlisted and the person's own store products are dropped. |
| `Discovery\RecommendationBuilder` | `rebuild()`; scheduled hourly as `emall:recommendations-rebuild` (also refreshes units sold). Editorial rules: table `complementary_rules(category_slug, complement_slug, note, position)`. |
| `Discovery\Homepage` | `for($actor, $region)` returns `HomeSection[]` (`key`, `title`, `basis`, `kind`, `items`, `evidence`, `personal`): `continue`, `interests`, `new_arrivals`, `trending`, `categories`, `stores`, `collections`, `searches`, `local`, `reorder`. A section with nothing to show is absent. |
| `Discovery\Collections` | Operator only: `create`, `update`, `delete`, `validateRule`. Public: `products($collection, $limit)`, `live()`. Manual lists or dynamic rules (category, brand, price, attributes, in stock, rating, sort) resolved through the search; start and end dates; `Collection::live()` scope. |

## 4. Wishlists, saved searches, alerts

* `Discovery\Wishlists`: `defaultFor`, `add($user, $product, $variantId, $note)`, `remove`, `has`, `productIdsFor`, `items` (flags `available`), `updateNote`, `moveToCart`, `enableSharing` / `disableSharing` (off by default, token minted on enable and cleared on disable), `shared($token)` (buyable products only, no notes).
* `Discovery\SavedSearches`: `save($user, $name, SearchRequest, 'off|daily|weekly')`, `setFrequency`, `newMatches`, `markSeen`. Limit 20.
* `Discovery\ProductAlerts`: `subscribe($user, $product, 'back_in_stock'|'price_drop', $target, $variantId)`, `cancel`, `lowestRecentPrice`.
* `Discovery\AlertEvaluator::run()` (command `emall:alerts-evaluate`, every 15 minutes, `withoutOverlapping`): claims each event with a conditional UPDATE, so a re-run or a concurrent run sends nothing twice. Notifications: `BackInStockNotification`, `PriceDropNotification`, `SavedSearchDigestNotification`, all `EmallNotification`s in the alerts category (the in-app row, and an e-mail on the shared layout when the person opted in). The price-drop notice quotes `PriceHistory::lowestSince()` for the last 30 days.
* Policies: `WishlistPolicy`, `SavedSearchPolicy`, `ProductAlertPolicy` (owner only).

## 5. Community

* `Community\ProductQuestions`: `ask`, `answer`, `withdraw`, `hideQuestion`, `publishQuestion`, `hideAnswer`, `forProduct($product, $viewer)`, `unansweredForStore($storeId)`. Policies `ProductQuestionPolicy`, `ProductAnswerPolicy`.
* `ReviewService` (extended, backwards compatible): `submit($user, $product, $rating, $body, $photos = [])`, `update`, `canEdit`, `moderate($operator, $review, 'published'|'removed')`, `deliveredItemFor`, `refreshAggregate` (published reviews only, also refreshes the store rating). `ReviewController::store` accepts `photos[]` and does not notify the seller of a held review. `ProductController::show` loads published reviews only. Review `scopePublished`.
* `Community\ReviewVotes::toggle`, `ReviewResponses::respond`, `ReviewPhotos` (private disk `local`, path `review-photos/{review}/{random}.jpg`; streamed by `GET /review-photos/{photo}`), `ReviewIntegrity::assess`, `StoreRatings::summary($store)` (`shown` only from 5 reviews). `ReviewPolicy` gained `update`, `respond`, `vote`, `report`, `moderate`.
* `Community\ContentReports::report($user, 'review'|'question'|'answer', $id, $reason, $details)`; reasons: spam, fake, offensive, off_topic, personal_info, other. `Community\ModerationQueue` (operator): `reported()`, `heldReviews()`, `heldQuestions()`, `counts()`, `resolve($operator, $type, $id, 'remove'|'dismiss', $note)`, `approve`.
* `php artisan emall:reviews-rebuild [--dry-run]` recomputes every product and store aggregate from the reviews.

## 6. Shopping assistant

`app(Assistant\ShoppingAssistant::class)->ask($text, $context)` returns an `AssistantAnswer` (`mode` `rules|model`, `understood`, `filters`, `total`, `catalogue` = facts per item, `suggestion` = headline, picks with `source`, refinements as search params, `notes`). `toArray()` labels the halves "From the catalogue" and "Our suggestion". JSON: `POST /assistant/ask` with `text` (`route('assistant.ask')`, throttle `assistant`: 8 a minute and 60 an hour). The model path is described in D-38.

## 7. Metrics

`Metrics\CommerceMetrics::all($from, $to)` returns `Metric[]` (`key`, `label`, `definition`, `unit`, `value`, `status` `ok|no_data`, `numerator`, `denominator`, `note`): discovery_success, search_to_product, add_to_cart_rate, checkout_completion, cart_abandonment, repeat_purchase_rate, return_rate (needs a `return_requests` table), refund_rate, gmv, aov, payment_failure_rate, cancellation_rate, seller_activity. Written definitions are in the class docblock. `value` is null (and `toArray()['display']` is "No data yet") when there is nothing to measure. "A session" means one person on one day. `MetricsCollector` (Dot.Analytics) adds aggregate engagement counts only with `ANALYTICS_COMMERCE_METRICS=true`.

## 8. Operations

| Command | Schedule | Purpose |
|---|---|---|
| `emall:search-reindex [--stale] [--status] [--synonyms]` | `--stale` every 5 minutes | build or repair the index |
| `emall:recommendations-rebuild` | hourly, `withoutOverlapping(45)` | co-purchase and co-view links, units sold |
| `emall:alerts-evaluate` | every 15 minutes, `withoutOverlapping(20)` | alerts and saved-search digests |
| `emall:discovery-prune` | daily 03:30 | retention |
| `emall:reviews-rebuild [--dry-run]` | manual | repair rating aggregates |

All are registered in `DiscoveryServiceProvider` (not in a route file). Limiters `suggest`, `assistant` and `community` are defined there too; `community` is for the Q&A, report and vote endpoints the feature agents add. Routes: only the marked block `// --- data-b ---` in `routes/web.php`.

## 9. Tables added

`search_documents`, `search_document_attributes`, `search_terms`, `search_synonyms`, `search_queries`, `product_views`, `product_relations`, `complementary_rules`, `commerce_events`, `wishlists`, `wishlist_items`, `saved_searches`, `product_alerts`, `product_questions`, `product_answers`, `content_reports`, `review_photos`, `review_votes`, `review_responses`, `store_rating_aggregates`, `collections`, `collection_products`; `reviews` gained `verified_purchase`, `order_item_id` (unique), `status`, `held_reason`, `helpful_count`, `body_hash`, `edited_at`, `moderated_by`, `moderated_at`. Migrations `2026_11_09_3000xx` to `3004xx`. Strings, not enums; portable.

## 10. Known gaps

* Delivery estimates are never guessed: the assistant omits the line for a store that has set no delivery method.
* The `community` limiter has no route yet. Wishlist, alert, Q&A, vote and report endpoints and every view belong to the feature agents.
* Guest (session) carts are not touched by `Wishlists::moveToCart`; it writes the signed-in cart through `CartManager` and answers `needs_variant` when a product has several choices and none was saved.
* No IP or account-age signal for review integrity (none is recorded).
