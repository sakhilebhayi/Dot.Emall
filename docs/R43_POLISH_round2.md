# R-43 polish round 2 (jury 2, weighted 7.05)

Branch `feat/r43-polish2`, port 9121, decisions D-200 to D-207.

## Skills
`impeccable` was invoked (its context step reported no PRODUCT.md, so it ran against the existing Shelf Edge DESIGN.md and code; craft floor read as a review, no palette or type change). `design-taste-frontend` and `emil-design-eng` were not invoked in this round (no desk-page or motion work); nothing else is claimed.

## Fixed (jury number, what, test)
- #2 Team entries removed from every account menu. `ResponsiveShellTest::test_no_account_menu_offers_teams_but_the_team_routes_still_answer`, and `test_the_account_menu_has_sign_out_and_no_team_entries_even_for_a_person_in_two_teams` (replaces the old switcher test).
- #8 Search note and Clear filters. `SearchPageTest::test_an_exact_match_is_not_apologised_for_and_clear_filters_is_hidden_when_nothing_is_applied`, `test_a_price_limit_read_from_the_words_that_had_to_be_dropped_is_still_said_out_loud` (the first fails on the old code).
- #3 Store page two-up on phones (6,123 px, was 10,713; scrollWidth 375). Browser check only.
- #7 Sticky total and Go to checkout bar on the phone cart. Browser check at 375 (dark); covered by the existing cart tests staying green.
- #5 Empty-state glyphs. `ChromePolishTest::test_the_empty_state_is_a_ticket_stub_whose_punch_carries_the_states_own_glyph`, `test_an_empty_state_with_no_icon_or_an_unknown_one_keeps_the_empty_ring_and_never_the_fallback_square`. Browser: cart and wishlist at 375 (dark).
- #6 Eager first-viewport thumbnails (cart lines, store page, wishlist, seller and operator lists). Not browser-timed: on loopback nothing is slow enough to show a grey box.
- #4 Home Collections even grid, stores list height. Browser at 1440 (dark).
- #11 Utility row wraps under 30rem. Not looked at in a browser (CSS only).
- #13 (part) Cancel item steps back beside "I have collected it". Not looked at in a browser.

## Not done
#9 hero price card, #10 CTA arrow nudge and press state, #12 review block two-column, #13 order-line thumbnails (needs the product images eager-loaded in the order page query), #14 category intro compression, #15 checkout stepper behaviour. #1 (product art) is out of scope as briefed.

## Not verified
Light mode and 390 for the home, cart and empty states (only dark at 375 and 1440 looked at); keyboard focus of the new cart bar; the skeleton versus no-photo distinction was not re-looked at.

## Counts
Full SQLite suite: 1660 tests, 1618 passed, 42 skipped, 0 failed (10,216 assertions). `sh tests/Support/signed-in-smoke.sh`: 153 pages, 0 failed. Pint run on app, resources and tests.
