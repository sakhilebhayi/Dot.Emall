---
version: alpha
name: Shelf Edge
description: The Dot.Emall design system. The price label is the unit of the interface and the waybill is the order. Label paper and indigo ink, Stamp indigo for action, Awning yellow from the logo for the strip, Schibsted Grotesk for text and prices, Martian Mono for printed codes.
colors:
  primary: "#4b58a8"
  secondary: "#1b1e36"
  tertiary: "#f2c82d"
  neutral: "#f6f7f4"
  ground-light: "#f6f7f4"
  surface-light: "#ffffff"
  sunken-light: "#eceee9"
  ink-light: "#1b1e36"
  ink-2-light: "#3a3f5e"
  ink-3-light: "#565c7b"
  line-light: "#dcdfd8"
  line-strong-light: "#7d839c"
  brand-light: "#4b58a8"
  brand-hover-light: "#3d4991"
  brand-soft-light: "#e6e9f8"
  on-brand-light: "#ffffff"
  ground-dark: "#161830"
  surface-dark: "#1f2240"
  raised-dark: "#282c4e"
  sunken-dark: "#111326"
  ink-dark: "#eceefa"
  ink-2-dark: "#c8ccea"
  ink-3-dark: "#a3a9cf"
  line-dark: "#353a59"
  line-strong-dark: "#6e75a0"
  brand-dark: "#aab4f4"
  brand-hover-dark: "#c0c8f8"
  brand-soft-dark: "#2b3160"
  on-brand-dark: "#161830"
  awning: "#f2c82d"
  on-awning: "#1b1e36"
  pos-light: "#0a6b4d"
  pos-bg-light: "#dcf1e8"
  cau-light: "#7a5200"
  cau-bg-light: "#fbeec2"
  crit-light: "#b3261e"
  crit-bg-light: "#fbe3e1"
  pos-dark: "#6fd9ae"
  pos-bg-dark: "#173a34"
  cau-dark: "#f2c82d"
  cau-bg-dark: "#3d3410"
  crit-dark: "#ff9d94"
  crit-bg-dark: "#4a2326"
typography:
  display:
    fontFamily: Schibsted Grotesk
    fontSize: 4.25rem
    fontWeight: 750
    lineHeight: 1.1
    letterSpacing: -0.015em
  headline:
    fontFamily: Schibsted Grotesk
    fontSize: 2rem
    fontWeight: 700
    lineHeight: 1.1
    letterSpacing: -0.015em
  body:
    fontFamily: Schibsted Grotesk
    fontSize: 1rem
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: Schibsted Grotesk
    fontSize: 0.875rem
    fontWeight: 650
    lineHeight: 1.3
  price:
    fontFamily: Schibsted Grotesk
    fontSize: 3rem
    fontWeight: 750
    lineHeight: 1
    letterSpacing: -0.01em
    fontFeature: "'lnum' 1"
  code:
    fontFamily: Martian Mono
    fontSize: 0.8125rem
    fontWeight: 500
    lineHeight: 1.4
rounded:
  label: 3px
  photo: 4px
  control: 6px
  sheet: 12px
  pill: 999px
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  xxl: 48px
  section: 72px
components:
  button-primary:
    backgroundColor: "{colors.brand-light}"
    textColor: "{colors.on-brand-light}"
    rounded: "{rounded.control}"
    height: 44px
    padding: 16px
  button-quiet:
    backgroundColor: "{colors.surface-light}"
    textColor: "{colors.ink-light}"
    rounded: "{rounded.control}"
    height: 44px
  button-danger:
    backgroundColor: "{colors.surface-light}"
    textColor: "{colors.crit-light}"
    rounded: "{rounded.control}"
    height: 44px
  input:
    backgroundColor: "{colors.surface-light}"
    textColor: "{colors.ink-light}"
    rounded: "{rounded.control}"
    height: 44px
  price-label:
    backgroundColor: "{colors.surface-light}"
    textColor: "{colors.ink-light}"
    rounded: "{rounded.label}"
    padding: 24px
  label-strip:
    backgroundColor: "{colors.awning}"
    textColor: "{colors.on-awning}"
    rounded: "{rounded.label}"
    height: 6px
  status-positive:
    backgroundColor: "{colors.pos-bg-light}"
    textColor: "{colors.pos-light}"
    rounded: "{rounded.label}"
  status-caution:
    backgroundColor: "{colors.cau-bg-light}"
    textColor: "{colors.cau-light}"
    rounded: "{rounded.label}"
  status-critical:
    backgroundColor: "{colors.crit-bg-light}"
    textColor: "{colors.crit-light}"
    rounded: "{rounded.label}"
  dialog:
    backgroundColor: "{colors.surface-light}"
    textColor: "{colors.ink-light}"
    rounded: "{rounded.sheet}"
---

## Overview

**Name:** Shelf Edge. The strip of card on a shelf that tells you what a thing costs. Dot.Emall is a marketplace, and the thing a shopper trusts or distrusts is the number at the end: price, delivery, total. The whole system is built around making that number honest and easy to read.

### Concept card

| | |
|---|---|
| **Idea** | The price label is the unit of the interface. One label states the price, what delivery costs to your address and the total, and says plainly when a figure is not known yet. The order that follows is a waybill. |
| **Metaphor** | The shelf-edge label with its tear-off perforation and the parcel's waybill. The market stall's awning hangs from the top of every shopping page (the logo's own awning). |
| **Signature** | `ds.price-label`: price in large numerals, a dashed perforation with a notch each side, then Delivery and Total rows, under a yellow label strip. Its partner is `ds.order-timeline`, the waybill: a real route of dated steps, a step with no date says "Not yet". |
| **Emotion** | Calm certainty before paying: nothing is hidden, nothing is rushed. |
| **Motion verb** | **Stamp.** A hover lifts the primary button 1px and walks its arrow 3px; a press travels 1px and settles; a count arrives with a short stamp-in (scale 1.5 to 1, 200ms); a sheet rises 14px and stops. Nothing bounces, nothing pulses at rest, nothing loops. |
| **Screenshot** | A product page: the photograph large on its backdrop, the label beside it reading R749.00, Delivery, Total, under the yellow strip. |

### Three concepts considered (awwwards discipline)

| Territory | Concept | Originality | Fit | Feasible | Portable (lower is better) |
|---|---|---|---|---|---|
| Retail shelf | **The price label** as the unit of the interface, the waybill as the order | 4 | 5 | 5 | 2 |
| Logistics | **The parcel**: every page is a box with a manifest, status as customs stamps | 4 | 3 | 3 | 3 |
| Street market | **The stall**: crates, bunting, hand-painted boards, a loud colourful bazaar | 3 | 3 | 4 | 4 |

The label wins because it sits at the point of decision, answers the most repeated complaint in the research (extra costs revealed late, 40 percent of abandoned carts) and cannot be moved to another product without losing its meaning. The market stall survives only as the awning scallops from the logo, and the parcel survives as the waybill.

### What it is not

Not the Amazon grid (white boxes, orange button), not the Takealot blue, not a SaaS dashboard. It avoids the three default looks: no cream with a high-contrast serif and terracotta, no near-black with one acid accent (the dark theme is deep indigo ink, the yellow is a strip and never a button), no broadsheet hairlines with zero radius. Product photography is the largest colour on every product surface.

## Colors

Named by role. Every value is a CSS custom property in `resources/css/tokens.css`, a `light-dark()` pair, so a view never names a hex and never needs a `dark:` variant.

| Token | Light | Dark | Use |
|---|---|---|---|
| `--ground` Label paper | #f6f7f4 | #161830 | page ground (a cool grey with a breath of green, not cream; deep indigo ink, not black) |
| `--surface` Shelf | #ffffff | #1f2240 | labels, panels, inputs |
| `--raised` | #ffffff | #282c4e | dialogs, dropdowns, search suggestions |
| `--sunken` Crate | #eceee9 | #111326 | utility row, footer, table hover, empty wells |
| `--photo-ground` | #eceee9 | #2b3052 | the backdrop behind product photographs |
| `--ink` Marker ink | #1b1e36 | #eceefa | text, headings, prices |
| `--ink-2` | #3a3f5e | #c8ccea | secondary text |
| `--ink-3` | #565c7b | #a3a9cf | quietest text allowed (still AA on every ground) |
| `--line` | #dcdfd8 | #353a59 | dividers |
| `--line-strong` | #7d839c | #6e75a0 | the edge of a control (3:1) |
| `--brand` Stamp indigo | #4b58a8 | #aab4f4 | the one action colour, links; from the logo chevron |
| `--brand-hover` | #3d4991 | #c0c8f8 | hover |
| `--brand-soft` | #e6e9f8 | #2b3160 | selected rows, info ground |
| `--on-brand` | #ffffff | #161830 | text on a brand fill |
| `--awning` Awning yellow | #f2c82d | #f2c82d | the logo's yellow: the label strip, awning scallops, count badge. Never body text, never a button fill. Text on it is `--on-awning` #1b1e36 |
| `--pos` Fresh | #0a6b4d on #dcf1e8 | #6fd9ae on #173a34 | in stock, paid, delivered |
| `--cau` Caution | #7a5200 on #fbeec2 | #f2c82d on #3d3410 | awaiting payment, low stock, needs action |
| `--crit` Brick | #b3261e on #fbe3e1 | #ff9d94 on #4a2326 | failed, declined, rejected |

Contrast is checked as numbers in `tests/Unit/DesignTokensTest.php`: text pairs at 4.5:1 or better, control edges and focus at 3:1, in both themes. The Tailwind palette used by the older Jetstream views is bridged to the same values (`tailwind.config.js`, "Tailwind bridge" in `tokens.css`). New views must not use Tailwind colour utilities.

Theme: class `light` or `dark` on `<html>`, set before first paint by an inline script from `localStorage["emall-theme"]` or else `prefers-color-scheme`, and followed live when no choice is stored. `ds.theme-toggle` switches it. It works on every page, Jetstream pages included.

## Typography

Two self-hosted faces (`public/fonts`, SIL OFL licences beside them), no Google Fonts, no icon font:

- **Schibsted Grotesk** (variable, 400 to 900): text, headings, prices, labels. Chosen for sturdy figures: a zero that reads as a zero (R749.00, never "R749.oo"), lining numerals, and a plain humanist warmth that is readable at 16px on a phone. Headings 700 to 750 at a tight 1.1 line height with -0.015em tracking.
- **Martian Mono** (variable, 100 to 800): printed codes only (order numbers, SKUs, tracking numbers, the 01 02 03 numerals of the how-it-works list). Never a label face.

Scale (fluid with `clamp`): `--text-xs` 13px caption, `--text-sm` 14px, `--text-base` 16px body, `--text-md` 18px, `--text-lg` 20 to 24px, `--text-xl` 24 to 32px, `--text-2xl` 30 to 48px, `--text-3xl` 36 to 68px. Labels, captions and table heads are sentence case, untracked, never small caps. Prices: `lining-nums`; inside tables and cart lines also `tabular-nums` so figures stack.

## Layout

- Container 80rem, gutters 16px phone, 24px tablet, 32px desktop. At 1920 the gutters grow; the measure and grids do not.
- Spacing is a 4px unit: 4, 8, 12, 16, 24, 32, 48, 72.
- Page bodies are bands and columns divided by hairlines and ground changes, not floating cards. Product listings use an auto-fill grid (`ds-grid`), the home page uses a scroll-snapping strip, a numbered list and a closing band so no page is only a grid of identical cards. Never four equal metric tiles in a template: `ds.stat` is a figure with a rule over it; give one the weight.
- Mobile first. Primary controls are 44px; fields use 16px text so iOS does not zoom; phones get a bottom bar (account, seller, operator) and a sticky buy bar (product page); tables stack into labelled blocks.

## Elevation and shape

Nothing in the page body has a shadow. Only layers that float above it do: `--lift-1` (dropdown, mega menu, search suggestions) and `--lift-2` (dialog, toast), both neutral black at low alpha, never a coloured glow, never glass or blur.

Radius follows the object: 3px for labels, badges and the price label; 4px for photographs; 6px for controls and flat panels; 12px for sheets and dialogs; pills only for the count badge and the toggle track. No thick coloured border on one side of a card; the only accents are the 6px yellow label strip across the top of a label and the 2px underline of the current tab.

## Components

Anonymous Blade components in `resources/views/components/ds/`, each documented at the top of its file (props, slots, behaviour). Styled by `ds-core.css`, `ds-forms.css`, `ds-ui.css`, `ds-commerce.css`. Behaviour is vanilla JS in `resources/js/app.js` driven by data attributes. A live gallery of every component in both themes, side by side, is at `/dev/kit` (local and testing only).

| Group | Components |
|---|---|
| Base | `button` (primary, quiet, danger, link; sm md lg; loading; block), `icon-button`, `icon`, `badge`, `status`, `alert`, `toasts`, `avatar`, `skeleton`, `dl`, `stat`, `section`, `page-header`, `empty-state` (a ticket stub, no icon slot), `panel`, `logo` (live-type wordmark: dot in ink, a punched yellow stop, emall in indigo, weight 800), `theme-toggle` |
| Forms | `field`, `input`, `textarea`, `select`, `checkbox`, `radio`, `toggle`, `quantity-stepper`, `option-picker` (chips, swatches), `search-field`, `sort-select`, `facet-group` |
| Commerce | `price`, `price-label`, `rating`, `product-card` (grid and row), `product-gallery`, `seller-trust`, `delivery-estimate`, `cart-line`, `order-timeline` (waybill), `address-block`, `review-card`, `progress-steps` |
| Navigation and layers | `breadcrumbs`, `pagination`, `tabs`, `tab-panel`, `table`, `dropdown`, `dropdown-item`, `dialog`, `confirm-dialog` |

Specs that matter:

- **price-label**: strip, store line, title, price (xl), perforation, Delivery row (Free, an amount, or "Enter a delivery address to see this"), Total row (price plus delivery when known, "Not known yet" otherwise), "Includes VAT" only when told. Never guesses.
- **price**: R, whole figure, cents; the visible figure is `aria-hidden` and a plain "R749.00" is read once. A "Lowest price in the last 30 days: R799.00" line only when the caller passes the real recorded low (never a seller-typed "was" price).
- **rating**: stars, score and count, with "Rated 4.6 out of 5 from 120 reviews"; draws nothing at zero reviews.
- **product-card**: no box, photograph first, store, title (stretched link), rating, price, "Only N left" at 5 or fewer real units, "Out of stock" tag and a dimmed photograph at zero. A wishlist slot sits over the photo.
- **product-gallery**: thumbnails, previous and next, arrow keys, swipe, click or tap to zoom (mouse pans), lazy loading after the first photo, aspect ratio set.
- **seller-trust**: rungs that say what they are and how they are known: checked (InfoDot), claimed (typed by the seller), metric (measured, with its sample), missing. Never an unbacked "verified".
- **order-timeline**: dated steps joined by a rail; done (filled check), current (ring), to do (dashed), stop (brick). Tracking details are labelled "from the seller".
- **dialog**: native `<dialog>` with `showModal()`: focus trap, Esc, inert page, focus returns; bottom sheet on phones; drawer variant for the menu.
- **search-field**: ARIA 1.2 combobox with a listbox; suggestions from a slot or from a JSON endpoint (`{"suggestions":[{"label","url","meta"}]}`).
- **table**: a real table; `data-label` on cells stacks it into labelled blocks under 40rem.

### Icons

One drawing grammar: 24 grid, 1.75 stroke, round caps and joins, `currentColor`, inline SVG from `App\Support\Icons`. search, cart, bag, user, heart, menu, close, chevron-down, chevron-up, chevron-left, chevron-right, arrow-right, arrow-left, plus, minus, check, filter, sort, sun, moon, pin, store, tag, bell, info, trash, edit, image, grid, list, home, receipt, sliders, logout, help, lock, shield-check, external, copy, bookmark, mail, loader, chart, upload, flag, percent, message, dots, zoom-in, star, eye, calendar, phone, building, sparkle, clock, card, box, truck, package-check, x-circle, undo, return, check-circle, ban, in-stock, alert-triangle, minus-circle, eye-off, hourglass, x-octagon.

## Status vocabulary

A status is always a word, a shape (an icon no other status shares) and a tone. Colour alone never carries it. Source: `App\Support\Status`, rendered by `<x-ds.status kind="order|product" state="...">`.

| Kind | State | Word | Shape | Tone |
|---|---|---|---|---|
| Order | awaiting_payment | Awaiting payment | clock | caution |
| Order | paid | Paid | card | positive |
| Order | packing | Packing | box | info |
| Order | shipped | Shipped | truck | info |
| Order | delivered | Delivered | package-check | positive |
| Order | cancelled | Cancelled | x-circle | neutral |
| Order | refunded | Refunded | undo | neutral |
| Order | return_requested | Return requested | return | caution |
| Order | return_approved | Return approved | check-circle | positive |
| Order | return_declined | Return declined | ban | critical |
| Order | payment_failed | Payment failed | alert-triangle | critical |
| Product | in_stock | In stock | in-stock (tick) | positive |
| Product | low_stock | Only N left (N is the real quantity, only when N is 5 or fewer) | alert-triangle | caution |
| Product | out_of_stock | Out of stock | minus-circle | neutral |
| Product | unlisted | Unlisted | eye-off | neutral |
| Product | pending_review | Pending review | hourglass | caution |
| Product | rejected | Rejected | x-octagon | critical |

The first version's words still map: `processing` is Packing, `pending_approval` is Pending review, `approved` is In stock. A bare `pending` prints "Pending" (an item the seller has not started).

## Motion

Verb: **stamp**. Ease-out `cubic-bezier(0.2, 0.7, 0.2, 1)` only. Press 90ms (translate 1px, scale 0.98), hover and colour 140ms, popovers and toasts 200ms, sheets 280ms. Only `transform` and `opacity` animate; never `transition: all`. A product photograph zooms 3.5 percent inside its frame on hover (pointer devices only). The awning, the label and the waybill are static: nothing pulses, loops or reveals on scroll, and all content is visible with scripting off. `prefers-reduced-motion: reduce` removes every transition and animation (one global rule in `base.css`). Focus rings are 2px, appear instantly, and pass 3:1 on every ground.

## Copy voice

Confident, warm, plain. Sentence case everywhere. Say what happens and what it costs. No em or en dashes in visible text, no exclamation marks, no hype words ("amazing", "unbeatable", "exclusive"), no fake urgency (no countdowns, no "12 people are viewing", no scarcity text unless it is a real remaining quantity of 5 or fewer), no invented counts. Say where a figure comes from ("Estimate from the seller's handling time and delivery method", "Tracking details come from the seller"). Errors name the problem and the way out. Buttons name the action ("Delete product", never "OK"). Accounts come from InfoDot, and every sign-in call to action says so in one sentence.

## Layout contexts

Four Blade layout components, one language, four postures. Each has one skip link, one `<main id="main">`, one h1 (from the `heading` slot, or the page's own), a unique `<title>`, a flash and banner region, toasts, a theme toggle and a notification bell for signed-in people. A strip across the very top marks the context: the hanging awning (store, account), a plain yellow strip (seller desk), an ink strip (operator).

| Layout | For | Slots (all optional except title) |
|---|---|---|
| `<x-store-layout>` | home, search, categories, product, store, cart, checkout, help, legal | `title`, `description`, `heading`, `lead`, `actions`, `breadcrumbs`, `location` (delivery chip), `suggestions` (search options), `testmode`, `banner`, `head`, `scripts`, default. Props `categories`, `robots`, `contained` (default true), `bottomBar` |
| `<x-account-layout>` | orders, returns, wishlist, saved searches, addresses, alerts, profile, teams, API tokens | same slots as store; rail on desktop, scrolling sub-nav and bottom bar on phones. `robots` defaults to noindex |
| `<x-seller-layout>` | seller desk: dashboard, products, orders, returns, shipping, promotions, analytics, store profile | `title`, `description`, `heading`, `lead`, `actions`, `breadcrumbs`, `banner`, `head`, `scripts`, default. Rail, bottom bar and "More" sheet; store name in the top bar |
| `<x-operator-layout>` | moderation, payments, reports, metrics | same as seller |
| `<x-auth-layout>` | sign in, reset, confirm, two-factor, verify | `title`, `heading`, `lead`, `footer`, default. A centred label |
| `<x-error-layout>` | 403, 404, 419, 429, 500, 503 | props `code`, `heading`, `message`, `hint`, `rich`; slot `actions`. With `rich` (403, 404, 419, 429) it draws inside the storefront layout, reading the session, with search and category links, and falls back to the standalone frame if that throws. 500 and 503 are standalone: no session, user, database or Livewire |

Navigation is data (`App\Support\Navigation`), linked through `emall_route('name')`, which returns `#` while a route is not registered and `emall_pending('name')` adds `data-pending-route="name"` to the link. `Shell::first([...])` picks the first registered of several names. The cart count is `Shell::cartCount()`, 0 until the cart feature binds `app()->bind('emall.cart_count', fn () => $count)`.

### Route names feature work must register

Existing and used: `home`, `login`, `logout`, `products.index`, `products.show`, `cart.index`, `orders.index`, `orders.show`, `profile.show`, `teams.show`, `teams.create`, `policy.show`, `terms.show`, `cookies`, `seller.products.index`, `seller.orders.index`, `seller.promo-codes.index`, `seller.store.create`, `seller.store.edit`, `operator.products.index`, `operator.payments.index`, `dashboard`.

Pending (the shell links to them now, as `#` with `data-pending-route`, until they exist):

| Name | Parameter | Used by |
|---|---|---|
| `search` | query `q` | header search action (falls back to `products.index`) |
| `search.suggest` | query `q`, JSON | autocomplete endpoint (the field turns it on when the route exists) |
| `categories.index`, `categories.show` | `category` (slug) | footer, mega menu (falls back to `products.index?category=`) |
| `stores.index`, `stores.show` | `store` | header, footer |
| `checkout.show` | none | cart |
| `wishlist.index` | none | account menu, footer, account rail |
| `account.home` | none | account overview (falls back to `dashboard`) |
| `account.returns`, `account.saved-searches`, `account.addresses`, `account.alerts` | none | account rail |
| `seller.dashboard` | none | seller rail (falls back to `dashboard`) |
| `seller.returns`, `seller.shipping`, `seller.analytics` | none | seller rail |
| `operator.reports`, `operator.metrics` | none | operator rail |
| `help.index`, `help.delivery`, `help.returns`, `help.contact`, `help.sellers` | none | footer, utility row |
| `sell.start` | none | "Sell on Emall" (falls back to `seller.store.create`) |

## Do and do not

Do: put the photograph first; show the total; say when a figure is unknown; use `ds` components and tokens; give every status a word and a shape; keep one primary button per view; write `data-label` on table cells; use `ds.confirm-dialog` for destructive actions; test both themes at 390 and 1440.

Do not: write a hex, an rgb value or a font name in a view; use Tailwind colour utilities or `dark:` variants in new views; add inline `onclick` handlers or inline `<script>` without a nonce (the CSP is enforced); load anything from a CDN; invent counts, ratings, urgency or "was" prices; put a rounded card in a gutter; put a thick border on one side of a card; use a gradient on text, glass, blur, radial glows or an icon in a tinted square; put four equal tiles in a row; mark a status with colour alone.

## Skills used

hallmark (floor and sweep, stamp, no tracked-capital labels), design-taste-frontend (anti-slop house style), awwwards (three concepts, concept card, motion verb), ui-ux-pro-max (checklist only: touch targets, contrast, focus, reduced motion; its stock palette and type were rejected), emil-design-eng (press scale 0.98, ease-out only, 200ms popovers, no `transition: all`), design-md (this file's format), impeccable (finish pass and the detector). Details and the tools that could not be run are in `docs/R43_DECISIONS_SHELL.md`.
