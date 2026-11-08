<?php

namespace Database\Seeders;

/**
 * The demo marketplace's shelves, as data: four stores and about 120 products on the real category tree
 * (emall:seed-categories), with brands, structured attributes and variants. Read by EmallDemoSeeder.
 *
 * A product row is [name, category slug, price, compare price, stock, picture shape, picture hue, description, options]:
 *
 *   options['flag']      pending | rejected | inactive            (the listing states the operator desk and seller list show)
 *   options['brand']     a brand name (real brands for electronics, the store's own label for homeware)
 *   options['attrs']     [attribute key => value], checked against the category's definitions
 *   options['matrix']    [option name => [values]]  one variant per combination (price, stock below)
 *   options['delta']     [option value => rand added to the base price]
 *   options['oos']       [[option name => value, ...]]  combinations that are sold out
 *   options['history']   [[days ago, price], ...] oldest first; the last price must equal the current one. A drop at least 30
 *                         days after the first record shows a "was" price; a rise (or a change older than the window) does not
 *   options['photos']    how many generated pictures (default 1 to 3)
 *
 * Pictures are generated neutral renders (no text, no brand marks); the brand names are only labels in the data.
 */
final class EmallDemoCatalogue
{
    /**
     * owner key => [store name, description, glyph, hue, profile]
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: array<string, mixed>}>
     */
    public static function stores(): array
    {
        return [
            'lerato' => ['Karoo Home & Hearth', 'Honest homeware for the kitchen, the braai and the stoep. Cast iron, stoneware, linen and candles, chosen by a family who cooks for a living.', 'hearth', 22, [
                'region' => 'Eastern Cape', 'city' => 'Graaff-Reinet', 'legal_name' => 'Karoo Hearth Trading (Pty) Ltd', 'registration_number' => '2016/412207/07', 'vat_registered' => true, 'vat_number' => '4120198765',
                'contact_phone' => '+27 49 555 0101', 'infodot' => true,
                'return_policy' => 'Unused items can be returned within 7 days of delivery. Cast iron that has been seasoned by you cannot be returned unless it is faulty.',
                'shipping_policy' => 'We pack Monday to Friday and ship with The Courier Guy. Orders paid before 12:00 leave the same working day.',
            ]],
            'johan' => ['Veld & Vine Outdoors', 'Camping and hiking gear tested on the Drakensberg and the Cederberg. Fair prices, straight advice, and we answer our own email.', 'mountain', 205, [
                'region' => 'KwaZulu-Natal', 'city' => 'Underberg', 'legal_name' => 'Veld and Vine Outdoors CC', 'registration_number' => '2012/118442/23', 'vat_registered' => false, 'vat_number' => null,
                'contact_phone' => '+27 33 555 0144', 'infodot' => true,
                'return_policy' => 'Return unused gear within 7 days of delivery in its original packaging. Tents must be returned dry.',
                'shipping_policy' => 'Gear ships from Underberg with Aramex. Collection from the shop is free.',
            ]],
            'zanele' => ['Ubuntu Beauty Co.', 'Small-batch skincare and wellness made from marula, aloe ferox, rooibos and shea. Cruelty free and packed in Durban.', 'leaf', 340, [
                'region' => 'KwaZulu-Natal', 'city' => 'Durban', 'legal_name' => null, 'registration_number' => null, 'vat_registered' => false, 'vat_number' => null,
                'contact_phone' => '+27 31 555 0166', 'infodot' => false,
                'return_policy' => 'For hygiene reasons opened skincare cannot be returned unless it is faulty. Unopened items can be returned within 7 days.',
                'shipping_policy' => 'Packed in Durban within two working days and sent by Pargo or courier.',
            ]],
            'kabelo' => ['Joburg Tech Hub', 'Everyday electronics and desk gear from Braamfontein: audio, chargers, keyboards and the cables nobody remembers to buy.', 'squares', 215, [
                'region' => 'Gauteng', 'city' => 'Johannesburg', 'legal_name' => 'Tech Hub Distribution (Pty) Ltd', 'registration_number' => '2019/663210/07', 'vat_registered' => true, 'vat_number' => '4660331209',
                'contact_phone' => '+27 11 555 0188', 'infodot' => true,
                'return_policy' => 'Faulty items are replaced or refunded. Unused items in sealed packaging can be returned within 7 days.',
                'shipping_policy' => 'Dispatched from Braamfontein the same day for orders paid before 14:00. Collection from the shop is free.',
            ]],
        ];
    }

    /**
     * @return array<string, list<array{0: string, 1: string, 2: int|float, 3: int|float|null, 4: int, 5: string, 6: int, 7: string, 8?: array<string, mixed>}>>
     */
    public static function products(): array
    {
        $clothing = ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['Black', 'Navy', 'Green']];

        return [
            'lerato' => [
                ['Cast Iron Potjie Pot 3L', 'cookware', 749, 899, 14, 'pot', 12, 'Pre-seasoned three-legged potjie with a tight lid. Holds heat for hours over coals, so stews finish themselves while you sit.', ['brand' => 'Karoo Hearth']],
                ['Enamel Camping Mug, Set of 4', 'dinnerware', 289, null, 40, 'mug', 205, 'Four 350ml enamel mugs with a rolled steel rim. Light enough for the pack, tough enough for the kitchen drawer.', ['brand' => 'Karoo Hearth']],
                ['Olive Wood Serving Board', 'dinnerware', 349, null, 22, 'board', 30, 'Cut from a single piece of olive wood, so no two grains match. Oiled with food-safe finish.'],
                ['Hand-Glazed Stoneware Bowl Set', 'dinnerware', 459, null, 3, 'bowl', 190, 'Four deep bowls glazed by hand in a Magaliesburg studio. Dishwasher and microwave safe.'],
                ['Cotton Throw Blanket, Sand', 'bedding-bath', 599, 729, 18, 'cloth', 38, 'A heavy woven cotton throw, 130 x 180 cm, with a knotted fringe. Washes soft.'],
                ['Woven Seagrass Basket', 'storage-organisation', 329, null, 25, 'basket', 42, 'Tightly woven storage basket with handles. Holds firewood, laundry or the dog toys.'],
                ['Beeswax Taper Candles, 6 Pack', 'home-decor', 159, null, 60, 'candle', 45, 'Six 25cm tapers poured from South African beeswax. Slow, smokeless burn.'],
                ['Terracotta Bud Vase', 'home-decor', 189, null, 0, 'vase', 18, 'A small unglazed terracotta vase for one stem. Currently sold out; the next firing is due in a few weeks.'],
                ['Linen Table Runner', 'home-decor', 259, null, 30, 'cloth', 95, 'Stonewashed linen, 40 x 180 cm, with a hand-rolled hem.'],
                ['Glass Storage Jars, Set of 3', 'storage-organisation', 319, null, 35, 'jar', 170, 'Three airtight jars with bamboo lids for rusks, rice and rooibos.'],
                ['Cast Iron Skillet 26cm', 'cookware', 529, null, 12, 'pot', 8, 'A well-balanced skillet for steak, cornbread and breakfast on the fire alike.', ['brand' => 'Karoo Hearth']],
                ['Braai Tongs, Stainless Steel', 'braai-outdoor-cooking', 149, null, 80, 'box', 215, '40cm tongs with a locking hinge and a heat-safe loop.'],
                ['Oak and Brass Wall Clock', 'home-decor', 649, null, 8, 'tub', 36, 'A silent-sweep wall clock with a solid oak face.', ['flag' => 'pending']],
                ['Fynbos Soy Candle', 'home-decor', 219, null, 44, 'candle', 130, 'A hand-poured soy candle scented with buchu and honeybush. 45 hour burn.'],
                ['Biltong Spice Rub 200g', 'pantry', 79, null, 120, 'jar', 20, 'Coriander, black pepper and a little brown sugar. Enough for 3kg of beef.'],
                // --- appliances and furniture
                ['Air Fryer 5.5L Digital', 'small-kitchen-appliances', 1599, 1899, 16, 'tub', 8, 'Eight presets, a dishwasher-safe basket and a window to watch the chips. 1700W.', ['brand' => 'Russell Hobbs']],
                ['Cordless Glass Kettle 1.7L', 'small-kitchen-appliances', 449, null, 33, 'bottle', 205, 'Borosilicate glass with a blue light and auto shut-off. Boils a litre in about three minutes.', ['brand' => 'Russell Hobbs']],
                ['Personal Blender 600W', 'small-kitchen-appliances', 599, null, 21, 'tub', 130, 'Two 570ml cups with travel lids. Crushes ice without a fuss.', ['brand' => 'Defy']],
                ['Two-Slice Toaster, Stainless', 'small-kitchen-appliances', 379, null, 27, 'box', 215, 'Variable browning, a bagel setting and a removable crumb tray.', ['brand' => 'Defy']],
                ['Drip Coffee Maker 1.25L', 'small-kitchen-appliances', 699, null, 12, 'pot', 22, 'Brews ten cups, keeps them warm for 30 minutes and has a reusable filter.', ['brand' => 'Russell Hobbs']],
                ['Bar Fridge 95L', 'fridges-freezers', 2999, 3399, 7, 'box', 205, 'A quiet under-counter fridge with a small freezer compartment and reversible door.', ['brand' => 'Defy', 'attrs' => ['capacity' => 95, 'energy_rating' => 'A']]],
                ['Double Door Fridge Freezer 410L', 'fridges-freezers', 8999, null, 4, 'box', 190, 'Frost-free with a water dispenser and separate temperature controls.', ['brand' => 'Hisense', 'attrs' => ['capacity' => 410, 'energy_rating' => 'B']]],
                ['Three-Seater Fabric Sofa', 'living-room', 6499, 7499, 0, 'box', 25, 'Hardwood frame, high-density foam and a washable cover. Delivered in two boxes, no tools needed.', ['matrix' => ['Colour' => ['Charcoal', 'Sand', 'Teal']], 'delta' => ['Teal' => 400], 'attrs' => ['assembly_required' => 'no', 'material' => 'Polyester']]],
                ['Oak Veneer Coffee Table', 'living-room', 1899, null, 9, 'board', 38, 'A low table with a lower shelf and rounded corners. Assembly takes about fifteen minutes.', ['attrs' => ['colour' => 'Brown', 'material' => 'Oak veneer', 'assembly_required' => 'yes']]],
                ['Five-Shelf Bookcase', 'living-room', 1299, null, 11, 'board', 30, 'Particleboard with a melamine finish, anchored to the wall with the strap supplied.', ['attrs' => ['colour' => 'Beige', 'material' => 'Melamine board', 'assembly_required' => 'yes']]],
                ['Bedside Pedestal, Two Drawer', 'bedroom', 899, null, 18, 'box', 42, 'Soft-close drawers on metal runners, ready assembled.', ['attrs' => ['colour' => 'Grey', 'material' => 'Engineered wood', 'assembly_required' => 'no']]],
                ['Cotton Duvet Set', 'bedding-bath', 749, null, 0, 'cloth', 190, 'A 200 thread-count cotton duvet cover and two pillowcases.', ['matrix' => ['Size' => ['Single', 'Double', 'Queen', 'King'], 'Colour' => ['White', 'Grey']], 'delta' => ['Double' => 150, 'Queen' => 300, 'King' => 450]]],
                ['Non-Stick Pan Set, 3 Piece', 'cookware', 699, null, 26, 'pot', 215, 'Induction-ready 20, 24 and 28cm pans with a PFOA-free coating.'],
                ['Pressure Cooker 6L', 'cookware', 1249, null, 8, 'pot', 12, 'Stainless steel with three safety valves. Tender beef in about forty minutes.', ['brand' => 'Karoo Hearth']],
            ],
            'johan' => [
                ['Alpine 2-Person Dome Tent', 'camping-hiking', 2499, 2999, 6, 'tent', 205, 'A freestanding two-pole dome with a full-coverage fly and a taped floor. 2.4kg packed.', ['brand' => 'Veld & Vine']],
                ['Sleeping Bag, Comfort -5C', 'camping-hiking', 1199, null, 15, 'bag', 18, 'Synthetic mummy bag rated to -5C comfort, with a compression sack.', ['brand' => 'Veld & Vine']],
                ['Inflatable Sleeping Mat', 'camping-hiking', 549, null, 20, 'tub', 160, 'R-value 3.2, packs to the size of a water bottle, inflates in twelve breaths.', ['brand' => 'Veld & Vine']],
                ['Trekking Poles, Pair', 'camping-hiking', 699, null, 11, 'box', 100, 'Three-section aluminium poles with cork grips and carbide tips.'],
                ['20L Daypack', 'camping-hiking', 899, 1099, 28, 'bag', 25, 'A ventilated back panel, rain cover and a hydration sleeve. Fits a full day on Table Mountain.', ['brand' => 'Veld & Vine']],
                ['Insulated Steel Bottle 750ml', 'camping-hiking', 299, null, 75, 'bottle', 195, 'Double-walled, cold for 24 hours and hot for 12. Fits most cup holders.'],
                ['LED Headlamp 400 Lumen', 'camping-hiking', 349, null, 52, 'device', 55, 'USB-C rechargeable headlamp with a red night mode. IPX6 water resistant.', ['brand' => 'Petzl']],
                ['Folding Camp Chair', 'camping-hiking', 429, null, 24, 'box', 200, 'Steel frame, 120kg rating, packs into its own carry bag.'],
                ['Gas Camping Stove', 'camping-hiking', 389, null, 16, 'device', 15, 'A compact single-burner stove with piezo ignition. Screws onto standard gas cartridges.'],
                ['Dry Bag 10L', 'camping-hiking', 199, null, 60, 'bag', 48, 'Roll-top dry bag in welded 210D nylon. Keeps the sleeping bag dry in a river crossing.'],
                ['Microfibre Travel Towel', 'camping-hiking', 179, null, 90, 'cloth', 180, 'Dries in minutes and packs down to a fist.'],
                ['Enamel Plate Set, 4 Piece', 'camping-hiking', 219, null, 34, 'bowl', 210, 'Four 22cm enamel plates that survive the campsite.'],
                ['Pocket Multi-Tool', 'camping-hiking', 329, null, 2, 'device', 0, 'Fourteen tools in a stainless steel body, with a nylon sheath.', ['brand' => 'Leatherman']],
                ['Wide-Brim Sun Hat', 'camping-hiking', 259, null, 40, 'bowl', 35, 'UPF 50+ with a chin cord and a vented crown.', ['flag' => 'pending']],
                ['Camp Cookset, 8 Piece', 'camping-hiking', 649, null, 9, 'pot', 140, 'Two pots, a pan, lids and a handle, nesting into one compact bundle.'],
                // --- clothing, shoes, cycling
                ['Merino Base Layer Tee', 'men-clothing', 549, null, 0, 'cloth', 205, 'A 160gsm merino blend that stays fresh for days on the trail.', ['brand' => 'Veld & Vine', 'matrix' => $clothing, 'oos' => [['Size' => 'XL', 'Colour' => 'Navy']], 'attrs' => ['material' => 'Wool']]],
                ['Fleece Pullover, Half Zip', 'men-clothing', 799, 949, 0, 'cloth', 160, 'A warm mid-layer in recycled polyester fleece with a chest pocket.', ['brand' => 'Veld & Vine', 'matrix' => $clothing, 'attrs' => ['material' => 'Polyester']]],
                ['Convertible Hiking Pants', 'men-clothing', 899, null, 0, 'cloth', 100, 'Zip-off legs, quick-dry ripstop and a gusseted crotch.', ['brand' => 'Veld & Vine', 'matrix' => ['Size' => ['30', '32', '34', '36'], 'Colour' => ['Khaki', 'Black']], 'attrs' => ['material' => 'Polyester']]],
                ['Rain Jacket, Packable', 'men-clothing', 1199, null, 0, 'cloth', 215, 'Taped seams, an adjustable hood and a pocket it stuffs into.', ['brand' => 'Veld & Vine', 'matrix' => ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['Black', 'Orange']], 'attrs' => ['material' => 'Polyester']]],
                ['Trail Leggings, High Waist', 'women-clothing', 649, null, 0, 'cloth', 300, 'Four-way stretch with a phone pocket and a flat waistband.', ['brand' => 'Veld & Vine', 'matrix' => ['Size' => ['XS', 'S', 'M', 'L'], 'Colour' => ['Black', 'Purple']], 'attrs' => ['material' => 'Polyester']]],
                ['Fleece Jacket, Full Zip', 'women-clothing', 849, null, 0, 'cloth', 340, 'A fitted full-zip fleece with zipped hand pockets.', ['brand' => 'Veld & Vine', 'matrix' => ['Size' => ['XS', 'S', 'M', 'L'], 'Colour' => ['Pink', 'Grey', 'Navy']], 'attrs' => ['material' => 'Polyester']]],
                ['Cotton Hiking Shirt', 'women-clothing', 499, null, 0, 'cloth', 140, 'A breathable long-sleeve shirt with roll-up tabs.', ['brand' => 'Veld & Vine', 'matrix' => ['Size' => ['S', 'M', 'L', 'XL'], 'Colour' => ['White', 'Blue']], 'attrs' => ['material' => 'Cotton']]],
                ['Trail Running Shoes', 'men-shoes', 1599, 1899, 0, 'box', 15, 'A 6mm drop with a grippy lug sole and a rock plate.', ['brand' => 'Salomon', 'matrix' => ['Shoe size' => ['7', '8', '9', '10', '11'], 'Colour' => ['Black', 'Grey']], 'oos' => [['Shoe size' => '7', 'Colour' => 'Grey']]]],
                ['Leather Hiking Boots', 'men-shoes', 2299, null, 0, 'box', 30, 'Full-grain leather, a waterproof membrane and a stiff midsole for heavy packs.', ['brand' => 'Merrell', 'matrix' => ['Shoe size' => ['7', '8', '9', '10', '11'], 'Colour' => ['Brown']]]],
                ['Trail Running Shoes, Women', 'women-shoes', 1549, null, 0, 'box', 330, 'The same lug sole on a narrower last.', ['brand' => 'Salomon', 'matrix' => ['Shoe size' => ['4', '5', '6', '7'], 'Colour' => ['Pink', 'Black']]]],
                ['Kids Fleece Hoodie', 'kids-clothing', 349, null, 0, 'cloth', 205, 'Soft brushed fleece in sizes for ages 4 to 12.', ['brand' => 'Veld & Vine', 'matrix' => ['Size' => ['4-5', '6-7', '8-9', '10-12'], 'Colour' => ['Navy', 'Red']], 'attrs' => ['material' => 'Cotton']]],
                ['Kids Rain Poncho', 'kids-clothing', 169, null, 38, 'cloth', 55, 'A bright poncho that folds into its own pouch.', ['attrs' => ['colour' => 'Yellow', 'material' => 'Polyester']]],
                ['Road Bike Helmet', 'cycling', 849, null, 0, 'bowl', 205, 'In-mould shell with 18 vents and a dial-fit cradle.', ['brand' => 'Giro', 'matrix' => ['Size' => ['S', 'M', 'L'], 'Colour' => ['White', 'Black']]]],
                ['Bicycle Light Set, USB', 'cycling', 299, null, 45, 'device', 55, 'A front and rear light that charge from USB-C and run for ten hours.'],
                ['Floor Pump with Gauge', 'cycling', 449, null, 19, 'tub', 160, 'A steel barrel, a wide base and a head that fits Presta and Schrader valves.'],
                ['Cable Bike Lock 1.8m', 'cycling', 229, null, 31, 'box', 0, 'A coiled cable lock with a key-and-code combination.'],
            ],
            'zanele' => [
                ['Marula Facial Oil 30ml', 'skincare', 265, null, 55, 'bottle', 38, 'Cold-pressed marula oil. Light, quick to absorb, and rich in oleic acid.', ['brand' => 'Ubuntu Beauty']],
                ['Rooibos Calming Face Mask', 'skincare', 149, null, 70, 'tub', 8, 'A clay and red rooibos mask that settles redness. Rinse after ten minutes.', ['brand' => 'Ubuntu Beauty']],
                ['Shea Butter Body Cream 250ml', 'skincare', 189, 229, 80, 'tub', 48, 'Whipped shea butter cream for dry winters on the Highveld.', ['brand' => 'Ubuntu Beauty']],
                ['Baobab Hand Cream 75ml', 'skincare', 99, null, 100, 'tub', 130, 'A non-greasy hand cream with baobab oil, sized for a handbag.', ['brand' => 'Ubuntu Beauty']],
                ['Rooibos Tea, 80 Bags', 'beverages', 69, null, 150, 'box', 10, 'Organic Cederberg rooibos in unbleached bags.', ['brand' => 'Ubuntu Beauty']],
                ['Aloe Ferox Gel 200ml', 'skincare', 119, null, 64, 'bottle', 120, 'Soothing gel for sun-exposed skin, made from wild-harvested aloe ferox.', ['brand' => 'Ubuntu Beauty']],
                ['Natural Deodorant Stick', 'personal-care', 89, null, 90, 'tub', 200, 'Aluminium free, with magnesium and a light cedar scent.', ['brand' => 'Ubuntu Beauty']],
                ['Charcoal Soap Bar', 'personal-care', 59, null, 200, 'box', 220, 'Cold-process soap with activated charcoal and tea tree.', ['brand' => 'Ubuntu Beauty']],
                ['Exfoliating Body Scrub 300g', 'skincare', 175, null, 40, 'jar', 22, 'Sea salt and sweet almond oil in a glass jar.', ['brand' => 'Ubuntu Beauty']],
                ['Lip Balm, Set of 3', 'skincare', 99, null, 85, 'device', 350, 'Honey, vanilla and mint. Beeswax based.', ['brand' => 'Ubuntu Beauty']],
                ['Rose Geranium Face Mist 100ml', 'skincare', 139, null, 33, 'bottle', 335, 'A fine mist of distilled rose geranium water.', ['brand' => 'Ubuntu Beauty']],
                ['Bamboo Hair Brush', 'haircare', 129, null, 27, 'board', 35, 'Soft boar-free bristles in a bamboo handle.'],
                ['Essential Oil Starter Kit', 'personal-care', 399, null, 14, 'box', 150, 'Six 10ml oils: lavender, eucalyptus, peppermint, lemongrass, tea tree and rose geranium.', ['brand' => 'Ubuntu Beauty']],
                ['Argan Hair Mask 250ml', 'haircare', 195, null, 22, 'jar', 30, 'A deep conditioning hair mask.', ['flag' => 'rejected', 'brand' => 'Ubuntu Beauty']],
                ['Lavender Bath Salts 500g', 'personal-care', 109, null, 48, 'jar', 270, 'Epsom and sea salt with lavender buds.', ['flag' => 'inactive', 'brand' => 'Ubuntu Beauty']],
                ['Mineral Sunscreen SPF 50, 100ml', 'skincare', 229, null, 47, 'bottle', 45, 'A non-nano zinc sunscreen that rubs in clear. Water resistant for 80 minutes.', ['brand' => 'Ubuntu Beauty']],
                ['Moringa Shampoo 300ml', 'haircare', 139, null, 52, 'bottle', 120, 'A gentle sulphate-free shampoo with moringa and aloe.', ['brand' => 'Ubuntu Beauty']],
                ['Moringa Conditioner 300ml', 'haircare', 139, null, 49, 'bottle', 130, 'Detangles without weighing hair down.', ['brand' => 'Ubuntu Beauty']],
                ['Vitamin C 1000mg, 60 Tablets', 'vitamins-supplements', 189, null, 120, 'jar', 40, 'Sixty tablets, one a day. Food supplement; not a substitute for a varied diet.', ['brand' => 'Solal']],
                ['Magnesium Glycinate, 90 Capsules', 'vitamins-supplements', 249, null, 66, 'jar', 270, 'Ninety capsules of chelated magnesium. Food supplement.', ['brand' => 'Solal']],
                ['Omega 3 Fish Oil, 120 Softgels', 'vitamins-supplements', 299, 349, 41, 'jar', 205, 'Molecularly distilled fish oil. Food supplement.', ['brand' => 'Solal']],
                ['Raw Wildflower Honey 500g', 'pantry', 119, null, 88, 'jar', 45, 'Unheated honey from Eastern Cape apiaries.'],
                ['First Aid Kit, Home', 'first-aid', 329, null, 23, 'box', 0, 'Dressings, plasters, a thermometer and a guide in a hard case.'],
            ],
            'kabelo' => [
                ['Wireless Over-Ear Headphones', 'headphones', 1299, 1599, 18, 'headphones', 215, 'Active noise cancelling, 40 hours of battery and a folding case.', ['brand' => 'Sony']],
                ['True Wireless Earbuds', 'headphones', 799, null, 35, 'device', 200, 'Bluetooth 5.3 earbuds with a wireless charging case.', ['brand' => 'JBL']],
                ['Portable Bluetooth Speaker', 'tv-audio-video', 649, null, 22, 'tub', 10, 'Waterproof (IP67) with a 12 hour battery and a carabiner loop.', ['brand' => 'JBL']],
                ['65W USB-C Fast Charger', 'chargers-cables', 449, null, 60, 'box', 220, 'Charges a laptop and a phone at once. Folding plug.', ['brand' => 'Anker']],
                ['10 000mAh Power Bank', 'chargers-cables', 399, null, 45, 'device', 205, 'Slim power bank with USB-C in and out. Fits a shirt pocket.', ['brand' => 'Anker']],
                ['Braided USB-C Cable 2m', 'chargers-cables', 119, null, 150, 'cloth', 25, 'Tested to 20 000 bends. Carries 100W.', ['brand' => 'Anker']],
                ['Aluminium Laptop Stand', 'office-electronics', 499, null, 28, 'board', 210, 'Raises the screen to eye level. Folds flat to 8mm.'],
                ['Mechanical Keyboard 75%', 'computer-components', 1499, null, 9, 'board', 260, 'Hot-swappable switches, aluminium case, Bluetooth and USB-C.', ['brand' => 'Keychron', 'history' => [[90, 1299], [45, 1499]]]],
                ['Wireless Mouse', 'computer-components', 299, null, 70, 'device', 190, 'Silent clicks, 2.4GHz receiver and Bluetooth, 12 month battery.', ['brand' => 'Logitech', 'history' => [[70, 249], [8, 299]]]],
                ['1080p Webcam', 'computer-components', 699, null, 14, 'device', 240, 'A wide-angle webcam with a privacy shutter and a built-in microphone.', ['brand' => 'Logitech']],
                ['Clear Phone Case', 'phone-cases', 149, null, 0, 'device', 200, 'Shock-absorbing clear case. Sold out; restock expected soon.'],
                ['Smart Plug, 2 Pack', 'plumbing-electrical', 279, null, 40, 'box', 150, 'Wi-Fi plugs with a power monitor and scheduling.'],
                ['Monitor Arm, Single', 'office-electronics', 899, null, 7, 'tub', 215, 'Gas-spring arm for screens up to 27 inches. Clamp or grommet mount.'],
                ['A5 Dotted Notebook', 'stationery', 89, null, 100, 'book', 225, '192 pages of 100gsm paper that does not bleed through.'],
                ['Gel Pen Set, 12 Colours', 'stationery', 129, null, 90, 'device', 330, 'Twelve smooth 0.5mm gel pens in a zip pouch.', ['flag' => 'pending']],
                // --- laptops
                ['Lenovo IdeaPad Slim 3, 15.6 inch', 'laptops', 8999, 9999, 12, 'device', 215, 'A light everyday laptop for study and office work. Full HD screen, fast start-up and a battery that lasts the lecture day.', ['brand' => 'Lenovo', 'attrs' => ['ram' => 8, 'storage' => 512, 'storage_type' => 'SSD', 'processor' => 'Intel Core i5-1235U', 'screen_size' => 15.6, 'operating_system' => 'Windows'], 'history' => [[80, 9999], [9, 8999]]]],
                ['Lenovo IdeaPad 5, 16GB RAM, 16 inch', 'laptops', 12999, null, 8, 'device', 205, 'More memory for many tabs and a bigger screen for spreadsheets. Backlit keyboard and a metal lid.', ['brand' => 'Lenovo', 'attrs' => ['ram' => 16, 'storage' => 512, 'storage_type' => 'SSD', 'processor' => 'AMD Ryzen 7 5825U', 'screen_size' => 16, 'operating_system' => 'Windows']]],
                ['HP 250 G9 Business Laptop', 'laptops', 6499, null, 15, 'device', 10, 'A dependable machine for small offices: numeric keypad, HDMI and a spill-resistant keyboard.', ['brand' => 'HP', 'attrs' => ['ram' => 8, 'storage' => 256, 'storage_type' => 'SSD', 'processor' => 'Intel Core i3-1215U', 'screen_size' => 15.6, 'operating_system' => 'Windows']]],
                ['Dell Inspiron 15 Laptop', 'laptops', 10499, null, 5, 'device', 220, 'Narrow bezels, a fingerprint reader and a full-size keyboard.', ['brand' => 'Dell', 'attrs' => ['ram' => 16, 'storage' => 512, 'storage_type' => 'SSD', 'processor' => 'Intel Core i5-1335U', 'screen_size' => 15.6, 'operating_system' => 'Windows']]],
                ['ASUS Vivobook 15 OLED', 'laptops', 14999, 16499, 4, 'device', 25, 'A bright OLED panel at a student price. 1.7kg.', ['brand' => 'ASUS', 'attrs' => ['ram' => 16, 'storage' => 512, 'storage_type' => 'SSD', 'processor' => 'AMD Ryzen 5 7530U', 'screen_size' => 15.6, 'operating_system' => 'Windows'], 'history' => [[60, 16499], [14, 14999]]]],
                ['Apple MacBook Air 13 inch M2', 'laptops', 20999, null, 3, 'device', 0, 'Fanless and silent with an all-day battery. 8GB memory, 256GB storage.', ['brand' => 'Apple', 'attrs' => ['ram' => 8, 'storage' => 256, 'storage_type' => 'SSD', 'processor' => 'Apple M2', 'screen_size' => 13.6, 'operating_system' => 'macOS'], 'history' => [[70, 21999], [7, 20999]]]],
                // --- phones and tablets
                ['Samsung Galaxy A15 Smartphone', 'smartphones', 3999, 4499, 0, 'device', 200, 'A 6.5 inch AMOLED screen, a 50MP camera and a two-day battery. Dual SIM.', ['brand' => 'Samsung', 'matrix' => ['Storage' => ['128GB', '256GB'], 'Colour' => ['Black', 'Blue']], 'delta' => ['256GB' => 800], 'oos' => [['Storage' => '256GB', 'Colour' => 'Blue']], 'attrs' => ['storage' => 128, 'ram' => 4, 'screen_size' => 6.5, 'network' => '4G', 'dual_sim' => 'yes']]],
                ['Samsung Galaxy A35 5G Smartphone', 'smartphones', 7499, null, 0, 'device', 215, 'A glass back, a bright 120Hz screen and 5G.', ['brand' => 'Samsung', 'matrix' => ['Storage' => ['128GB', '256GB'], 'Colour' => ['Navy', 'Lilac']], 'delta' => ['256GB' => 1000], 'attrs' => ['storage' => 128, 'ram' => 6, 'screen_size' => 6.6, 'network' => '5G', 'dual_sim' => 'yes']]],
                ['Samsung Galaxy S24 5G Smartphone', 'smartphones', 16999, 18999, 0, 'device', 230, 'A compact flagship with a bright 6.2 inch screen and a long software promise.', ['brand' => 'Samsung', 'matrix' => ['Storage' => ['128GB', '256GB', '512GB'], 'Colour' => ['Onyx', 'Marble']], 'delta' => ['256GB' => 1500, '512GB' => 3500], 'attrs' => ['storage' => 128, 'ram' => 8, 'screen_size' => 6.2, 'network' => '5G', 'dual_sim' => 'yes']]],
                ['Xiaomi Redmi Note 13 Smartphone', 'smartphones', 3499, null, 0, 'device', 35, 'A big 120Hz AMOLED screen and a 108MP camera for the price.', ['brand' => 'Xiaomi', 'matrix' => ['Storage' => ['128GB', '256GB'], 'Colour' => ['Black', 'Green']], 'delta' => ['256GB' => 700], 'attrs' => ['storage' => 128, 'ram' => 6, 'screen_size' => 6.67, 'network' => '4G', 'dual_sim' => 'yes']]],
                ['Huawei Nova Y72 Smartphone', 'smartphones', 3299, null, 14, 'device', 190, 'A large battery and a 90Hz screen at an entry price.', ['brand' => 'Huawei', 'attrs' => ['storage' => 128, 'ram' => 8, 'screen_size' => 6.7, 'network' => '4G', 'dual_sim' => 'yes']]],
                ['Apple iPhone 14 Smartphone', 'smartphones', 14999, null, 0, 'device', 0, 'A dual-camera system and the A15 chip. Sold with a USB-C to Lightning cable.', ['brand' => 'Apple', 'matrix' => ['Storage' => ['128GB', '256GB'], 'Colour' => ['Midnight', 'Blue']], 'delta' => ['256GB' => 2000], 'attrs' => ['storage' => 128, 'ram' => 6, 'screen_size' => 6.1, 'network' => '5G', 'dual_sim' => 'yes']]],
                ['Samsung Galaxy Tab A9 Tablet', 'tablets', 3299, null, 10, 'device', 205, 'An 8.7 inch tablet for reading, video calls and the kids on a road trip.', ['brand' => 'Samsung', 'attrs' => ['storage' => 64, 'ram' => 4, 'screen_size' => 8.7, 'connectivity' => 'Wi-Fi']]],
                ['Lenovo Tab M10, 10.1 inch', 'tablets', 2999, null, 7, 'device', 215, 'A 10.1 inch screen and a metal back. Wi-Fi and cellular.', ['brand' => 'Lenovo', 'attrs' => ['storage' => 64, 'ram' => 4, 'screen_size' => 10.1, 'connectivity' => 'Wi-Fi + Cellular']]],
                // --- screens
                ['Samsung 55 Inch Crystal UHD Smart TV', 'tv-audio-video', 10999, 12999, 9, 'board', 205, 'A 4K Crystal processor, three HDMI ports and the apps South Africans use: Showmax, YouTube and Netflix.', ['brand' => 'Samsung', 'attrs' => ['screen_size' => 55, 'resolution' => '4K'], 'history' => [[90, 12999], [11, 10999]]]],
                ['Samsung 43 Inch Full HD Smart TV', 'tv-audio-video', 5999, null, 13, 'board', 215, 'A compact smart TV for a bedroom or kitchen.', ['brand' => 'Samsung', 'attrs' => ['screen_size' => 43, 'resolution' => 'Full HD']]],
                ['Samsung 65 Inch QLED 4K Smart TV', 'tv-audio-video', 21999, null, 4, 'board', 230, 'Quantum dot colour and a 120Hz panel for sport and gaming.', ['brand' => 'Samsung', 'attrs' => ['screen_size' => 65, 'resolution' => '4K']]],
                ['LG 55 Inch UHD Smart TV', 'tv-audio-video', 11499, null, 6, 'board', 10, 'A 4K panel with webOS and a magic remote.', ['brand' => 'LG', 'attrs' => ['screen_size' => 55, 'resolution' => '4K']]],
                ['Hisense 50 Inch 4K Smart TV', 'tv-audio-video', 7999, null, 11, 'board', 25, 'A VIDAA smart TV with Dolby Vision for the lounge.', ['brand' => 'Hisense', 'attrs' => ['screen_size' => 50, 'resolution' => '4K']]],
                ['Dell 24 Inch Full HD Monitor', 'monitors', 1999, null, 20, 'board', 215, 'An IPS panel with a height-adjustable stand and HDMI.', ['brand' => 'Dell', 'attrs' => ['screen_size' => 24, 'resolution' => 'Full HD', 'refresh_rate' => 75]]],
                ['LG 27 Inch 4K UHD Monitor', 'monitors', 5499, 6199, 8, 'board', 10, 'A 4K IPS panel for photo editing and spreadsheets alike.', ['brand' => 'LG', 'attrs' => ['screen_size' => 27, 'resolution' => '4K', 'refresh_rate' => 60]]],
                ['Samsung 27 Inch Curved Gaming Monitor', 'monitors', 4299, null, 6, 'board', 205, 'A 1500R curve and a 165Hz refresh rate.', ['brand' => 'Samsung', 'attrs' => ['screen_size' => 27, 'resolution' => '2K', 'refresh_rate' => 165]]],
            ],
        ];
    }
}
