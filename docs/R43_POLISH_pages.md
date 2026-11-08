# R-43 polish: PAGES (outside jury defects #3, #12 to #16, #18, #19, #21, #22)

Branch `feat/r43-polish-pages`. Decisions D-190 to D-198 (docs/R43_DECISIONS.md).

## Skills

Invoked with the Skill tool: `impeccable` (polish, craft-floor read), `design-taste-frontend` (app-screens rules for the desk pages). `emil-design-eng` was **unavailable** ("Unknown skill"), so no claim is made for it; hover and press details here reuse the existing button and row states and add none of their own beyond the orders-row hover and the tab states. Palette, type and the Shelf Edge concept are unchanged; no new font, no CDN; the one new motion (smooth scroll of the active tab into view) is `auto` under `prefers-reduced-motion`.

## What was checked and how

Own SQLite database and port 9113, demo seed, own headless browser session (the shared browser pane was being driven by other agents, so it was not used). Looked at screenshots at 1440 (and 390 for the PDP tabs, the checkout, both phone bars), light, and dark for the order page and the checkout.

| # | Defect | Result |
|---|---|---|
| 3 | Checkout gating, steps, heading | Fixed (D-190). Signed in with a saved address: Pay is enabled on first load, steps 1 and 2 say "Done" with a tick, the manual address form and its heading are gone until "Use a different address" is chosen. Server side unchanged: tampered total, another buyer's address and a missing terms box are refused (tests). Guests still press "Show delivery options". |
| 12 | Search | Brand filter: it was already not clipped (the panel scrolls as one); added "Show N more" (D-191). "tv" and the speaker: root cause found and fixed (D-192): the word became a category filter, nothing was left to rank by, the newest listing won. The speaker is still listed because it is in the category named by the removable chip. The half-empty second row of six results in a four-column grid is **not changed**. |
| 13 | PDP tabs on a phone | Fixed (D-194): fit at 390, fade when they overflow, selected tab marked. The row is not sticky, so the marked tab is visible only while the row is in view (not changed: a sticky bar would have to be placed under the site header, which this pass does not own). |
| 14 | PDP small print | Fixed (D-193). |
| 15 | Seller orders | Date order, Ship to column, rail label fixed (D-195). "Fix seed dates" was not needed: the order was the defect. |
| 16 | Product editor | One save per form with unsaved state, aligned photo row, bar no longer covers headings (D-196). The reported "Save details twice" did not reproduce: the page has one "Save details" (test asserts it). |
| 18 | Phone bars | "Queue", padding, nowrap (D-197). Seller bar looked right at 390 after the padding. |
| 19 | Metrics chart | 12px axis text, three ticks, download icon (D-197). |
| 21 | Orders list | Row opens the order, one secondary action, caption hidden, newest by date (D-198). |
| 22 | Order timeline | "Not recorded", status line, key, row layout (D-198). The "Collected" ring is now explained by the key as "Next step". |

## Tests

New: `tests/Feature/Polish/PagesPolishTest.php` (14 tests): checkout auto-confirm and its server checks, guest unchanged, hidden address heading, search ranking (fails on the old engine, checked), facet "show more", PDP disclosure, orders list order and row, waybill, seller parcel and dashboard date order, no Ship to column, editor dirty state, operator bar label, metrics axes and icon. Whole suite (sqlite): 1575 tests, 1537 passed, 38 skipped (same skips as before), 0 failed. `sh tests/Support/signed-in-smoke.sh`: 153 pages, 0 failed. Pint run on touched PHP.

## Not done or not verified

- Search ranking on PostgreSQL (`ts_rank_cd` over the whole vector) is not tested here; the change only adds words to the score, never to the filter.
- The auto re-price on changing a saved address or option (checkout.js) was not driven in a browser; the controller path it calls (`checkout.delivery`) is the existing one.
- No keyboard-only pass over the orders-row overlay link beyond the focus outline rule.
- Files outside the brief touched on purpose: `ds-core.css` (progress steps "Done" state), `ds-commerce.css` (waybill), `ds/facet-group` (+ new `_facet-row`), `ds/progress-steps`, `Icons.php` (new `download` icon), `Navigation.php` (`short` label), `desk-bottom-bar.blade.php`, `operator.css` (desk shell touch-ups). Other agents editing the same files will need a trivial merge.
