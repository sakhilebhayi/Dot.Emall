# R-43 buyer-side QA (branch feat/r43-qa-buyer)

Method: the demo world on its own SQLite file (port 9101, host `qabuyer.localhost`, `EMALL_E2E_FAKE_PAYSTACK=true`), driven over HTTP with real sessions and CSRF tokens (curl), in a private Playwright browser (desktop 1280 and 375 px) and with signed webhooks. Nothing was marked paid by hand: payments went through the fake Paystack page and the signed `/webhooks/paystack` endpoint. Rows were edited only to set up a situation (a stock number, an expiry date, an order's age).

## Journeys

| # | Journey | Result |
|---|---|---|
| 1 | First-time visitor: home, search (typo "lapto", "labtop", "telly", "samsng tv", nonsense, empty), facets and price/brand filters, sorts, suggest, category page, product page with variants, delivery region, VAT line, "was" price, stock wording | Pass, with defects fixed (below). "Was" price shows only where the price record supports it (TV 113) and never from the seller-typed compare price (phone 107). Low stock is the real number at 5 or fewer. Delivery is "Choose where it is going" until a province is chosen. |
| 2 | Guest: cart, promo (valid, expired, unknown, wrong seller), checkout, fake Paystack failure then retry then success, the e-mails in the log, order via link, wrong, expired, other-order and no-grant tokens, 429 | Pass. Wrong, other-order and expired tokens give the same 404 (no oracle). Defect: that 404 gave a guest with a lost link no way forward (fixed, D-140). Payment-failure e-mail and "Try payment again" work; a retry creates a new attempt and the old one is flagged `needs_review` if it is ever paid. |
| 3 | Signed-in buyers: multi-seller cart and checkout with saved addresses, per-seller delivery, payment, webhook replay, cancel a line on an unpaid order (total recalculated, delivery of the emptied parcel dropped), hold expiry, late payment on a cancelled order, return with photos and the seller's and buyer's views, refund display | Pass. Webhook: bad signature 401; an amount mismatch is `needs_review`; the same signed event posted three times gives one payment and no extra stock movement; a payment for an already-paid or cancelled order is flagged, never applied twice. Return: photos are re-encoded, only the buyer, that seller and the operator can open them (others 404), seller approve and receive writes the refund, buyer page shows "Refunded" with "not complete until Paystack confirms". |
| 4 | Concurrency | 10 parallel `POST /checkout` (6 guests, 4 signed-in) for the last unit of a variant: exactly one 302 to Paystack, nine back to the cart with "Out of stock now", stock 0, three stock movements for three orders earlier in the run reconcile. A double-submit of the same checkout token from one buyer made one order. **SQLite only**: row locks are no-ops there; PostgreSQL concurrency is covered by the repository's own PostgreSQL tests, not re-run here. |
| 5 | Reviews, questions, wishlist, alerts, saved searches, assistant, notifications | Reviews: refused for a product never bought (403), for the seller's own product, a second review by the same buyer; forged `verified_purchase`/`status`/`user_id` fields ignored; bad photo, five photos, fake image refused with messages. Wishlist share link: no owner name or notes, `noindex`, 404 once sharing is off. Alerts: duplicate refused, unknown kind ignored. Saved searches: create, rename, set digest, another user gets 403. Assistant: defects fixed (D-143). Notification choices: orders cannot be switched off, alerts e-mail opt-in works. |
| 6 | Account | Data export is the user's own JSON with `no-store` and no other person's data; deletion refused for Thandi with every reason listed (button disabled); Nomsa: wrong password refused, right password closes the account (anonymised to "Former customer"). |
| 7 | Errors, empty states, keyboard, console, 375 px | 404, 403, 419, 429 are designed pages; 405 and 413 were the framework default (fixed). Every GET page of a 300-page crawl as a guest, Thandi (250) and Nomsa (120) answers 200 with no `data-pending-route` and no PHP error text. 375 px: no horizontal overflow on 18 public pages and on cart and checkout. Keyboard: skip link present and targets `#main` on every page tried; every focusable element on home, product, search, cart, checkout has a visible focus style (the site search shows it on its wrapper); no unnamed control; one `h1` and one `main` each. Console: only the expected 404 on the nonexistent page. |

## Defects fixed (commit, test)

| Defect | Fix | Test |
|---|---|---|
| Product specs dropped units ("Memory (RAM) 16") | D-141 | `ProductPageTest::test_a_specification_with_a_unit_prints_the_unit_so_the_number_means_something` |
| A 2-4 MB photo (inside the promised 4 MB) failed as "did not upload" when PHP's own limit was lower | D-142 | `QaBuyer\UploadLimitMessageTest` (3 tests) |
| Paid panel said "will pack your order" on delivered and refunded orders | D-145 | `PaymentReturnTest::test_once_the_goods_have_been_delivered_the_paid_panel_no_longer_says_the_seller_will_pack_it` |
| Assistant: "found 11 products that fit" for nonsense, "tell" read as Dell, no word about data it does not hold | D-143 | `ShoppingAssistantTest` (4 new tests; all fail without the fix) |
| Search order note "No search words" for a query whose words became filters | D-143 | `SearchPageTest::test_words_that_all_became_filters_are_not_reported_as_no_search_words` |
| Account-closing reasons ungrammatical for several orders | D-145 | `AccountErasureTest::test_several_unfinished_orders_are_listed_in_the_plural` |
| Profile page promised permanent erasure of everything | D-145 | `DeleteAccountTest::test_the_profile_page_does_not_promise_that_everything_is_erased` |
| Lost or expired guest order link: dead end | D-140 | `QaBuyer\GuestOrderLinkRecoveryTest` (7 tests) |
| 405 and 413 showed the framework default | D-144 | `EcosystemErrorPagesTest` (2 new cases) and `GuestOrderLinkRecoveryTest::test_a_wrong_method_and_an_oversized_upload_get_designed_pages_not_the_framework_default` |
| Retry toast named a button that does not exist; guest banner claimed the page opened from an e-mail | D-145 | existing payment and order page tests still pass (wording only) |

## Seen and not changed

- A buyer reading another person's order (`/orders/1` as Pieter) gets a designed 403, so the order id's existence is visible. Moving to 404 is a one-line policy change; left for the owner.
- Search suggest has no typo tolerance ("samsn" suggests nothing, the full search corrects it). Left.
- The search "relaxed" fallback (any of your words) lists loosely related products for a long nonsense query. It says so ("Ranked by how many of your words each product matches"); the assistant now says it too.
- The seeded "Shipped within the promised time 0%" on Joburg Tech Hub comes from the demo's 0-day handling time against real hours to ship; it is the seed, not the code.
- Order status "Refunded" shows while the refund is only "asked of Paystack"; the text under it says it is not complete until Paystack confirms.
- Seller-typed descriptions in the demo carry claims the platform did not write (for example "restock expected soon"); the page labels descriptions as seller-written.
- A shared wishlist can list an out-of-stock product (labelled "Out of stock"); D-90 said buyable only.

## Not tested or not verified

- PostgreSQL (nothing was run on it; the 10-way checkout race ran on SQLite, which serialises writes).
- Back-in-stock and price-drop alerts firing end to end: the alert rows and notice rendering were seen, but a seller restock through the real seller form was not driven (the stock endpoint I called is not the variant form). The alert tests in the repository cover it.
- Deleting Thandi's account through the profile modal (the privacy page shows the refusal; the modal reuses the same reasons through a validation error, covered by `AccountErasureTest`, not clicked).
- Digest and alert e-mails; the e-mail layout beyond the plain-text part of the guest mails.
- Multi-variant checkout (size and colour) end to end and a stock-hold expiry for a variant order (expiry ran on single-variant orders).
- Dark mode and screen readers. Keyboard work was a scripted focus audit plus one real Tab check, not a full tab-order walk.
- Real Paystack, real delivery estimates, and anything needing the network.
