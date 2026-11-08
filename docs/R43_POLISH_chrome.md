# R-43 polish: CHROME (layouts, header, footer, home, store page, errors, empty states, sign-in, buttons)

Branch `feat/r43-polish-chrome`, cut from `feat/r43-emall`. Decisions D-180 to D-189 (docs/R43_DECISIONS.md). Regression tests: `tests/Feature/Polish/ChromePolishTest.php` (13 tests).

## Skills actually used
- `impeccable` (invoked; its context script reported no PRODUCT.md, so the existing DESIGN.md "Shelf Edge" was the authority; craft-floor read before editing).
- `design-taste-frontend` (invoked; its page-draw and inspector were NOT run, because the brief fixes the palette, faces and concept, and the house style there conflicts with the existing system on purpose: rules, perforations, eyebrow-free headings were applied by hand only where compatible).
- `emil-design-eng`: **unavailable** (the Skill tool answered "Unknown skill" and `~/.claude/skills/emil-design-eng` is an empty directory). The press and hover timings were taken from DESIGN.md's motion section instead (ease-out only, 90ms press, 140ms hover, transform only, no `transition: all`).

## Defects, one by one
| # | Fix | Verified |
|---|---|---|
| 4 button | Stamp hover (D-182): primary lifts 1px, trailing arrow travels 3px, leading glyph grows 12%, press settles at +1px scale .98; pointer devices and `no-preference` only | Computed styles on the real "Add to cart" while hovering: `translateY(-1px)`, darker fill, icon `scale(1.12)`. Reduced-motion branch is in the media query; not toggled in a browser |
| 5 wordmark | Live heavy type, ink "dot", punched yellow stop with ink ring, indigo "emall" (D-180) | Zoomed 3.5x in light and dark: reads "dot.emall", the double l is clear. Owner decision needed on replacing the thin picture |
| 6 mobile header | Compact after scroll, search icon, badges top right, no count fade, one-line util row (D-181) | 390px: first paint, scrolled (clipped to one row, header layout height unchanged), icon click returns to top and focuses the field (checked with `document.activeElement`) |
| 7 mobile hero | Total label straight after the CTA, short photo (D-185) | 390px screenshot |
| 10 duplicate nav | Sub-nav removed from the account layout (D-183) | `/orders` at 390: bottom bar only |
| 11 store page | Products and chips first, trust summary plus "How this is measured" (D-184) | 1440 and 390, light and dark; chips scroll on phones |
| 17 error pages | Rich 404/403/419/429 plus fallback route (D-187) | Guest and signed-in (Thandi, Lerato) at 1440 and 390, dark and light; signed-in smoke passes with route:cache |
| 23 home rhythm | Cut near-you, sell band, duplicate Ask button; slim ask row; side-headed Trending; provenance under content (D-186) | 1440 full scroll, 390 |
| 24 empty states | Ticket stub, no icon slot (D-188) | Empty cart at 1440 and 390, light |
| 25 sign-in | Two-half ticket, primary InfoDot, quiet password button, reveal (D-189) | 1440 light and dark, 390 light |

## Tests
Full suite after the work: **1574 tests, 1536 passed, 38 skipped** (the PostgreSQL-only ones), 9590 assertions, `DB_CONNECTION=sqlite DB_DATABASE=:memory:`. Before my tests it was 1561 tests. Signed-in smoke (`sh tests/Support/signed-in-smoke.sh`): 153 pages, 0 failed, with routes, config and events cached.
Changed on purpose: `HomePageTest` (the "See sellers near you" and "Start selling" copy no longer exists on the home page; the province chip and the header sell link carry them), `DesignSystemTest::test_error_pages_render_standalone_with_one_h1` (403/404/419/429 are now rich with a fallback; 500/503 keep the strict no-Livewire check).

## Could not fix, or not verified
- Imagery, tiles and the gallery belong to the other agents: the generic pictogram in the hero photo and the Electronics tile still show.
- "Trending: 7 views and 0 sold in the last 7 days" under the hero total is still printed. It is the real basis of the pick; the jury called it weak proof. It needs an owner decision (do not feature a product with 0 sales) and a change in `HomeController`, outside my files.
- The collections block on the home page leaves the right third empty on wide screens (disc-collection-panel is not mine).
- `prefers-reduced-motion` was not emulated in the browser; the hover transform sits inside a `prefers-reduced-motion: no-preference` query and the global reduce rule in `base.css` still applies to everything else.
- Tablet widths (768 to 1023) were not looked at, apart from the breakpoint logic (the phone header and bottom bar apply below 64rem).
- Keyboard walk of the compact header (Tab order with the search row `visibility: hidden`) was reasoned, not driven.
- The thin "emall" PNG wordmarks are still in `public/images` (unreferenced by layouts); the mail header uses the mark only.
- A stale empty category tree appeared in the dev file cache once after a full phpunit run with a file cache driver; clearing the cache fixed it. Not investigated.
