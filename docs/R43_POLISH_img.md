# R-43 polish "IMG": product pictures, photo states, gallery

Branch `feat/r43-polish-img`, port 9111, decisions D-170 to D-174. Jury defects: #1, #2, #8, #9, #20 and the product-art part of #23.

## Skills
`impeccable` was invoked (after the first implementation pass, so its craft-floor was applied as a review, not before the first edit). `emil-design-eng` is unavailable in this environment (Skill tool: unknown skill). `design-taste-frontend` was not invoked (storefront components, not a desk page). Nothing else is claimed.

## What changed
- `database/seeders/EmallDemoArt.php` (new): the demo pictures. About 95 honest silhouettes chosen from the product name; seeder calls `EmallDemoArt::render($name, $hue, $frame)`; the old five generic drawers and `renderProduct` left `EmallDemoSeeder.php` (logos keep their helpers). Contact sheets of all 120 products at frame 0 (four sheets) and frame 1 and 2 samples were looked at; fixes made after the first look: runner, pan handles, headlamp strap, daypack straps, dry bag (a cross read as first aid), tea box (an X), headphone cord.
- `ds.product-card`, `ds.product-gallery`, `ds.cart-line`, `ds-commerce.css`: no-photo tiles, reserved boxes, eager thumbnails, skeleton, dark dimming.
- `HomeController::categoryShelf`, `welcome.blade.php` (one line), `discover.css`: lead category tile as a mosaic.
- `EmallDemoCatalogue.php`: Charcoal Soap Bar has `photos => 0` to show the honest state.

## Verified in the browser (own tab, port 9111)
Home categories (1440 dark, 390 light), product page duvet (390 dark and light, 1440 dark), no-photo product page (1440 and 390 dark), no-photo card in the personal-care category grid (1440 light), search list view at 390 dark with the no-photo row. Gallery thumbnails at 390 all painted.

## Not verified
Light mode at 1440 for the product gallery; 1440 dark home hero; cart-line "No photo" and wishlist in a browser (covered by a Blade render test only); the emptied thumbnail defect was not reproduced locally (all three thumbnails loaded lazily on a fast loopback), the fix is the eager load plus reserved size; hover motion and keyboard paths were not re-tested; DESIGN.md says "nothing pulses": the skeleton pulses five times then rests, a deliberate loading-state exception; the pages agent's "Sold by" muted link colour in `product.css` is untouched (jury #20 second half only partly done: card store label lifted).
