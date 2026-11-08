<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\PromoCode;
use App\Models\Review;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Notifications\NewReviewNotification;
use App\Notifications\OrderItemStatusUpdatedNotification;
use App\Notifications\OrderLineCancelledNotification;
use App\Notifications\UnpaidOrderCancelledNotification;
use App\Services\CheckoutService;
use App\Services\OrderLifecycle;
use App\Services\PaymentService;
use App\Services\ReviewService;
use GdImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A believable, fictional South African marketplace for design and audit work.
 *
 * Everything is built through the real models and services (CheckoutService,
 * PaymentService, OrderLifecycle, ReviewService...), so totals, snapshots,
 * stock, refunds and ratings are exactly what the live code produces. The only
 * things written directly are timestamps (history is back-dated), the
 * Paystack facts that normally arrive from Paystack (payment attempts), and
 * the handful of unverified legacy reviews that the application itself would
 * refuse to create.
 *
 * Never runs in production. Every demo account uses an @emall-demo.test
 * address, which is how purge() finds its own data again. Password for every
 * demo account: "password".
 */
class EmallDemoSeeder extends Seeder
{
    public const DOMAIN = 'emall-demo.test';

    public const PASSWORD = 'password';

    private const SIZE = 800;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Store> */
    private array $stores = [];

    /** @var array<string, StoreCategory> */
    private array $categories = [];

    /** @var array<string, Product> */
    private array $products = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The Dot.Emall demo seeder never runs in production.');
        }

        if ($this->alreadySeeded()) {
            $this->command?->info('Demo data is already present. Nothing was changed. Use --fresh to rebuild it.');

            return;
        }

        // Nothing here may reach the network: Paystack replies are faked for the duration.
        $refundId = 700000 + (int) DB::table('refunds')->max('id') * 7;

        Http::fake([
            '*paystack*/refund' => function () use (&$refundId) {
                return Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => ++$refundId * 13 + random_int(0, 9), 'status' => 'processed']]);
            },
            '*' => Http::response(['status' => false, 'message' => 'offline'], 503),
        ]);

        $this->makeUsers();
        $this->makeCategories();
        $this->makeStores();
        $this->makeProducts();
        $this->makePromoCodes();
        $this->makeOrders();
        $this->makeReviews();
        $this->makeExtraNotifications();
        $this->backdateNotifications();

        $this->command?->info(sprintf(
            'Demo marketplace ready: %d users, %d stores, %d products, %d orders, %d reviews. Password for every account: %s',
            count($this->users), count($this->stores), count($this->products),
            Order::query()->whereIn('user_id', $this->buyerIds())->count(),
            Review::query()->whereIn('product_id', collect($this->products)->pluck('id'))->count(),
            self::PASSWORD,
        ));
    }

    public function alreadySeeded(): bool
    {
        return User::query()->where('email', 'like', '%@'.self::DOMAIN)->exists();
    }

    /**
     * Removes every row (and file) the seeder made, and nothing else.
     */
    public function purge(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The Dot.Emall demo seeder never runs in production.');
        }

        DB::transaction(function () {
            $userIds = User::query()->where('email', 'like', '%@'.self::DOMAIN)->pluck('id');

            if ($userIds->isNotEmpty()) {
                $storeIds = Store::query()->whereIn('owner_id', $userIds)->pluck('id');
                $orderIds = Order::query()->whereIn('user_id', $userIds)->pluck('id');

                DB::table('payment_events')->whereIn('order_id', $orderIds)->delete();
                DB::table('refunds')->whereIn('order_id', $orderIds)->delete();
                DB::table('payments')->whereIn('order_id', $orderIds)->delete();
                DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
                DB::table('order_items')->whereIn('store_id', $storeIds)->delete();
                DB::table('orders')->whereIn('id', $orderIds)->delete();
                DB::table('cart_items')->whereIn('user_id', $userIds)->delete();
                DB::table('reviews')->whereIn('user_id', $userIds)->delete();

                foreach (Product::withTrashed()->whereIn('store_id', $storeIds)->get() as $product) {
                    DB::table('reviews')->where('product_id', $product->id)->delete();
                    $product->forceDelete(); // the model's own hook removes the photo files
                }

                foreach (Store::query()->whereIn('id', $storeIds)->get() as $store) {
                    if ($store->logo_path) {
                        Storage::disk('public')->delete($store->logo_path);
                    }
                }

                DB::table('promo_codes')->whereIn('store_id', $storeIds)->delete();
                DB::table('stores')->whereIn('id', $storeIds)->delete();
                DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $userIds)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();

                $teamIds = DB::table('teams')->whereIn('user_id', $userIds)->pluck('id');
                DB::table('team_user')->whereIn('team_id', $teamIds)->delete();
                DB::table('team_invitations')->whereIn('team_id', $teamIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->update(['current_team_id' => null]);
                DB::table('teams')->whereIn('id', $teamIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }

            // Demo categories go once nothing else uses them.
            $slugs = array_keys($this->categoryDefinitions());
            StoreCategory::query()->whereIn('slug', $slugs)->whereDoesntHave('products')->get()->each->delete();
        });
    }

    // ------------------------------------------------------------------ people

    /**
     * @return array<string, array{name: string, role: string, address: string, phone: string}>
     */
    private function userDefinitions(): array
    {
        return [
            'thandi' => ['name' => 'Thandi Mokoena', 'role' => 'buyer', 'address' => '14 Jacaranda Avenue, Parkhurst, Johannesburg, 2193', 'phone' => '+27 82 555 0143'],
            'pieter' => ['name' => 'Pieter van der Merwe', 'role' => 'buyer', 'address' => '22 Kloof Street, Gardens, Cape Town, 8001', 'phone' => '+27 83 555 0177'],
            'naledi' => ['name' => 'Naledi Dlamini', 'role' => 'buyer', 'address' => '7 Florida Road, Morningside, Durban, 4001', 'phone' => '+27 71 555 0120'],
            'sipho' => ['name' => 'Sipho Khumalo', 'role' => 'buyer', 'address' => '310 Church Street, Arcadia, Pretoria, 0083', 'phone' => '+27 60 555 0199'],
            'ayesha' => ['name' => 'Ayesha Patel', 'role' => 'buyer', 'address' => '5 Stamford Hill Road, Greyville, Durban, 4023', 'phone' => '+27 72 555 0188'],
            'lerato' => ['name' => 'Lerato Mahlangu', 'role' => 'seller', 'address' => '', 'phone' => ''],
            'johan' => ['name' => 'Johan Botha', 'role' => 'seller', 'address' => '', 'phone' => ''],
            'zanele' => ['name' => 'Zanele Nkosi', 'role' => 'seller', 'address' => '', 'phone' => ''],
            'kabelo' => ['name' => 'Kabelo Sithole', 'role' => 'seller', 'address' => '', 'phone' => ''],
            'operator' => ['name' => 'Marcus Williams', 'role' => 'operator', 'address' => '', 'phone' => ''],
            'nomsa' => ['name' => 'Nomsa Zulu', 'role' => 'new', 'address' => '', 'phone' => ''],
        ];
    }

    private function makeUsers(): void
    {
        foreach ($this->userDefinitions() as $key => $def) {
            $user = User::factory()->withPersonalTeam()->create([
                'name' => $def['name'],
                'email' => $key.'@'.self::DOMAIN,
                'password' => self::PASSWORD,
                'email_verified_at' => now()->subDays(random_int(60, 120)),
            ]);

            if ($def['role'] === 'operator') {
                $user->forceFill(['is_platform_operator' => true])->save();
            }

            $this->users[$key] = $user;
        }
    }

    /**
     * @return array<int, int>
     */
    private function buyerIds(): array
    {
        return collect($this->userDefinitions())
            ->filter(fn ($d) => $d['role'] === 'buyer')
            ->keys()
            ->map(fn ($k) => $this->users[$k]->id)
            ->all();
    }

    // -------------------------------------------------------------- catalogue

    /**
     * @return array<string, array{0: string, 1: ?string}> slug => [name, parent slug]
     */
    private function categoryDefinitions(): array
    {
        return [
            'home-kitchen' => ['Home & Kitchen', null],
            'cookware' => ['Cookware', 'home-kitchen'],
            'home-decor' => ['Home Decor', 'home-kitchen'],
            'outdoors' => ['Outdoors & Camping', null],
            'bags-travel' => ['Bags & Travel', null],
            'beauty' => ['Beauty & Wellness', null],
            'food-pantry' => ['Food & Pantry', null],
            'electronics' => ['Electronics', null],
            'audio' => ['Audio', 'electronics'],
            'accessories' => ['Phone & Laptop Accessories', 'electronics'],
            'stationery' => ['Stationery & Office', null],
        ];
    }

    private function makeCategories(): void
    {
        foreach ($this->categoryDefinitions() as $slug => [$name, $parent]) {
            $this->categories[$slug] = StoreCategory::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'parent_id' => $parent ? $this->categories[$parent]->id : null],
            );
        }
    }

    private function makeStores(): void
    {
        $defs = [
            'lerato' => ['Karoo Home & Hearth', 'Honest homeware for the kitchen, the braai and the stoep. Cast iron, stoneware, linen and candles, chosen by a family who cooks for a living.', 'hearth', 22],
            'johan' => ['Veld & Vine Outdoors', 'Camping and hiking gear tested on the Drakensberg and the Cederberg. Fair prices, straight advice, and we answer our own email.', 'mountain', 205],
            'zanele' => ['Ubuntu Beauty Co.', 'Small-batch skincare and wellness made from marula, aloe ferox, rooibos and shea. Cruelty free and packed in Durban.', 'leaf', 340],
            'kabelo' => ['Joburg Tech Hub', 'Everyday electronics and desk gear from Braamfontein: audio, chargers, keyboards and the cables nobody remembers to buy.', 'squares', 215],
        ];

        foreach ($defs as $owner => [$name, $description, $glyph, $hue]) {
            $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
            $logo = 'store-logos/'.$slug.'.png';

            Storage::disk('public')->put($logo, $this->renderLogo($glyph, $hue));

            $this->stores[$owner] = Store::create([
                'owner_id' => $this->users[$owner]->id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'logo_path' => $logo,
                'is_active' => true,
            ]);
        }
    }

    /**
     * name, category, price, compare price, stock, shape, hue, description, flag
     *
     * @return array<string, array<int, array{0: string, 1: string, 2: float, 3: ?float, 4: int, 5: string, 6: int, 7: string, 8?: string}>>
     */
    private function productDefinitions(): array
    {
        return [
            'lerato' => [
                ['Cast Iron Potjie Pot 3L', 'cookware', 749, 899, 14, 'pot', 12, 'Pre-seasoned three-legged potjie with a tight lid. Holds heat for hours over coals, so stews finish themselves while you sit.'],
                ['Enamel Camping Mug, Set of 4', 'cookware', 289, null, 40, 'mug', 205, 'Four 350ml enamel mugs with a rolled steel rim. Light enough for the pack, tough enough for the kitchen drawer.'],
                ['Olive Wood Serving Board', 'cookware', 349, null, 22, 'board', 30, 'Cut from a single piece of olive wood, so no two grains match. Oiled with food-safe finish.'],
                ['Hand-Glazed Stoneware Bowl Set', 'cookware', 459, null, 3, 'bowl', 190, 'Four deep bowls glazed by hand in a Magaliesburg studio. Dishwasher and microwave safe.'],
                ['Cotton Throw Blanket, Sand', 'home-decor', 599, 729, 18, 'cloth', 38, 'A heavy woven cotton throw, 130 x 180 cm, with a knotted fringe. Washes soft.'],
                ['Woven Seagrass Basket', 'home-decor', 329, null, 25, 'basket', 42, 'Tightly woven storage basket with handles. Holds firewood, laundry or the dog toys.'],
                ['Beeswax Taper Candles, 6 Pack', 'home-decor', 159, null, 60, 'candle', 45, 'Six 25cm tapers poured from South African beeswax. Slow, smokeless burn.'],
                ['Terracotta Bud Vase', 'home-decor', 189, null, 0, 'vase', 18, 'A small unglazed terracotta vase for one stem. Currently sold out; the next firing is due in a few weeks.'],
                ['Linen Table Runner', 'home-decor', 259, null, 30, 'cloth', 95, 'Stonewashed linen, 40 x 180 cm, with a hand-rolled hem.'],
                ['Glass Storage Jars, Set of 3', 'home-kitchen', 319, null, 35, 'jar', 170, 'Three airtight jars with bamboo lids for rusks, rice and rooibos.'],
                ['Cast Iron Skillet 26cm', 'cookware', 529, null, 12, 'pot', 8, 'A well-balanced skillet for steak, cornbread and breakfast on the fire alike.'],
                ['Braai Tongs, Stainless Steel', 'cookware', 149, null, 80, 'box', 215, '40cm tongs with a locking hinge and a heat-safe loop.'],
                ['Oak and Brass Wall Clock', 'home-decor', 649, null, 8, 'tub', 36, 'A silent-sweep wall clock with a solid oak face.', 'pending'],
                ['Fynbos Soy Candle', 'home-decor', 219, null, 44, 'candle', 130, 'A hand-poured soy candle scented with buchu and honeybush. 45 hour burn.'],
                ['Biltong Spice Rub 200g', 'food-pantry', 79, null, 120, 'jar', 20, 'Coriander, black pepper and a little brown sugar. Enough for 3kg of beef.'],
            ],
            'johan' => [
                ['Alpine 2-Person Dome Tent', 'outdoors', 2499, 2999, 6, 'tent', 205, 'A freestanding two-pole dome with a full-coverage fly and a taped floor. 2.4kg packed.'],
                ['Sleeping Bag, Comfort -5C', 'outdoors', 1199, null, 15, 'bag', 18, 'Synthetic mummy bag rated to -5C comfort, with a compression sack.'],
                ['Inflatable Sleeping Mat', 'outdoors', 549, null, 20, 'tub', 160, 'R-value 3.2, packs to the size of a water bottle, inflates in twelve breaths.'],
                ['Trekking Poles, Pair', 'outdoors', 699, null, 11, 'box', 100, 'Three-section aluminium poles with cork grips and carbide tips.'],
                ['20L Daypack', 'bags-travel', 899, 1099, 28, 'bag', 25, 'A ventilated back panel, rain cover and a hydration sleeve. Fits a full day on Table Mountain.'],
                ['Insulated Steel Bottle 750ml', 'outdoors', 299, null, 75, 'bottle', 195, 'Double-walled, cold for 24 hours and hot for 12. Fits most cup holders.'],
                ['LED Headlamp 400 Lumen', 'outdoors', 349, null, 52, 'device', 55, 'USB-C rechargeable headlamp with a red night mode. IPX6 water resistant.'],
                ['Folding Camp Chair', 'outdoors', 429, null, 24, 'box', 200, 'Steel frame, 120kg rating, packs into its own carry bag.'],
                ['Gas Camping Stove', 'outdoors', 389, null, 16, 'device', 15, 'A compact single-burner stove with piezo ignition. Screws onto standard gas cartridges.'],
                ['Dry Bag 10L', 'bags-travel', 199, null, 60, 'bag', 48, 'Roll-top dry bag in welded 210D nylon. Keeps the sleeping bag dry in a river crossing.'],
                ['Microfibre Travel Towel', 'bags-travel', 179, null, 90, 'cloth', 180, 'Dries in minutes and packs down to a fist.'],
                ['Enamel Plate Set, 4 Piece', 'outdoors', 219, null, 34, 'bowl', 210, 'Four 22cm enamel plates that survive the campsite.'],
                ['Pocket Multi-Tool', 'outdoors', 329, null, 2, 'device', 0, 'Fourteen tools in a stainless steel body, with a nylon sheath.'],
                ['Wide-Brim Sun Hat', 'bags-travel', 259, null, 40, 'bowl', 35, 'UPF 50+ with a chin cord and a vented crown.', 'pending'],
                ['Camp Cookset, 8 Piece', 'outdoors', 649, null, 9, 'pot', 140, 'Two pots, a pan, lids and a handle, nesting into one compact bundle.'],
            ],
            'zanele' => [
                ['Marula Facial Oil 30ml', 'beauty', 265, null, 55, 'bottle', 38, 'Cold-pressed marula oil. Light, quick to absorb, and rich in oleic acid.'],
                ['Rooibos Calming Face Mask', 'beauty', 149, null, 70, 'tub', 8, 'A clay and red rooibos mask that settles redness. Rinse after ten minutes.'],
                ['Shea Butter Body Cream 250ml', 'beauty', 189, 229, 80, 'tub', 48, 'Whipped shea butter cream for dry winters on the Highveld.'],
                ['Baobab Hand Cream 75ml', 'beauty', 99, null, 100, 'tub', 130, 'A non-greasy hand cream with baobab oil, sized for a handbag.'],
                ['Rooibos Tea, 80 Bags', 'food-pantry', 69, null, 150, 'box', 10, 'Organic Cederberg rooibos in unbleached bags.'],
                ['Aloe Ferox Gel 200ml', 'beauty', 119, null, 64, 'bottle', 120, 'Soothing gel for sun-exposed skin, made from wild-harvested aloe ferox.'],
                ['Natural Deodorant Stick', 'beauty', 89, null, 90, 'tub', 200, 'Aluminium free, with magnesium and a light cedar scent.'],
                ['Charcoal Soap Bar', 'beauty', 59, null, 200, 'box', 220, 'Cold-process soap with activated charcoal and tea tree.'],
                ['Exfoliating Body Scrub 300g', 'beauty', 175, null, 40, 'jar', 22, 'Sea salt and sweet almond oil in a glass jar.'],
                ['Lip Balm, Set of 3', 'beauty', 99, null, 85, 'device', 350, 'Honey, vanilla and mint. Beeswax based.'],
                ['Rose Geranium Face Mist 100ml', 'beauty', 139, null, 33, 'bottle', 335, 'A fine mist of distilled rose geranium water.'],
                ['Bamboo Hair Brush', 'beauty', 129, null, 27, 'board', 35, 'Soft boar-free bristles in a bamboo handle.'],
                ['Essential Oil Starter Kit', 'beauty', 399, null, 14, 'box', 150, 'Six 10ml oils: lavender, eucalyptus, peppermint, lemongrass, tea tree and rose geranium.'],
                ['Argan Hair Mask 250ml', 'beauty', 195, null, 22, 'jar', 30, 'A deep conditioning hair mask.', 'rejected'],
                ['Lavender Bath Salts 500g', 'beauty', 109, null, 48, 'jar', 270, 'Epsom and sea salt with lavender buds.', 'inactive'],
            ],
            'kabelo' => [
                ['Wireless Over-Ear Headphones', 'audio', 1299, 1599, 18, 'headphones', 215, 'Active noise cancelling, 40 hours of battery and a folding case.'],
                ['True Wireless Earbuds', 'audio', 799, null, 35, 'device', 200, 'Bluetooth 5.3 earbuds with a wireless charging case.'],
                ['Portable Bluetooth Speaker', 'audio', 649, null, 22, 'tub', 10, 'Waterproof (IP67) with a 12 hour battery and a carabiner loop.'],
                ['65W USB-C Fast Charger', 'accessories', 449, null, 60, 'box', 220, 'Charges a laptop and a phone at once. Folding plug.'],
                ['10 000mAh Power Bank', 'accessories', 399, null, 45, 'device', 205, 'Slim power bank with USB-C in and out. Fits a shirt pocket.'],
                ['Braided USB-C Cable 2m', 'accessories', 119, null, 150, 'cloth', 25, 'Tested to 20 000 bends. Carries 100W.'],
                ['Aluminium Laptop Stand', 'accessories', 499, null, 28, 'board', 210, 'Raises the screen to eye level. Folds flat to 8mm.'],
                ['Mechanical Keyboard 75%', 'accessories', 1499, null, 9, 'board', 260, 'Hot-swappable switches, aluminium case, Bluetooth and USB-C.'],
                ['Wireless Mouse', 'accessories', 299, null, 70, 'device', 190, 'Silent clicks, 2.4GHz receiver and Bluetooth, 12 month battery.'],
                ['1080p Webcam', 'accessories', 699, null, 14, 'device', 240, 'A wide-angle webcam with a privacy shutter and a built-in microphone.'],
                ['Clear Phone Case', 'accessories', 149, null, 0, 'device', 200, 'Shock-absorbing clear case. Sold out; restock expected soon.'],
                ['Smart Plug, 2 Pack', 'accessories', 279, null, 40, 'box', 150, 'Wi-Fi plugs with a power monitor and scheduling.'],
                ['Monitor Arm, Single', 'accessories', 899, null, 7, 'tub', 215, 'Gas-spring arm for screens up to 27 inches. Clamp or grommet mount.'],
                ['A5 Dotted Notebook', 'stationery', 89, null, 100, 'book', 225, '192 pages of 100gsm paper that does not bleed through.'],
                ['Gel Pen Set, 12 Colours', 'stationery', 129, null, 90, 'device', 330, 'Twelve smooth 0.5mm gel pens in a zip pouch.', 'pending'],
            ],
        ];
    }

    private function makeProducts(): void
    {
        $i = 0;

        foreach ($this->productDefinitions() as $owner => $rows) {
            foreach ($rows as $row) {
                [$name, $category, $price, $compare, $stock, $shape, $hue, $description] = $row;
                $flag = $row[8] ?? null;

                $product = Product::create([
                    'store_id' => $this->stores[$owner]->id,
                    'category_id' => $this->categories[$category]->id,
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                    'description' => $description,
                    'price' => $price,
                    'compare_price' => $compare,
                    'stock' => $stock,
                    'sku' => strtoupper(substr($owner, 0, 2)).'-'.str_pad((string) (++$i), 4, '0', STR_PAD_LEFT),
                    'is_active' => $flag !== 'inactive',
                    'status' => match ($flag) {
                        'pending' => 'pending_approval',
                        'rejected' => 'rejected',
                        default => 'approved',
                    },
                    'rejected_reason' => $flag === 'rejected' ? 'Please list the full ingredients and the batch number on the product description before this can be approved.' : null,
                    'reviewed_by' => in_array($flag, [null, 'inactive', 'rejected'], true) ? $this->users['operator']->id : null,
                    'reviewed_at' => in_array($flag, [null, 'inactive', 'rejected'], true) ? now()->subDays(random_int(20, 70)) : null,
                ]);

                // Two or three photos on every third listing so the gallery has something to do.
                $photos = $i % 3 === 0 ? 3 : ($i % 3 === 1 ? 1 : 2);
                for ($n = 0; $n < $photos; $n++) {
                    $path = "product-images/{$product->id}/".Str::random(40).'.jpg';
                    Storage::disk('public')->put($path, $this->renderProduct($shape, $hue, $n, crc32($name)));
                    ProductImage::create(['product_id' => $product->id, 'path' => $path, 'sort_order' => $n]);
                }

                $created = now()->subDays(random_int(25, 85))->subMinutes(random_int(0, 1400));
                $product->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();

                $this->products[$name] = $product;
            }
        }
    }

    private function makePromoCodes(): void
    {
        $rows = [
            ['lerato', 'KAROO10', 'percent', 10, null, null, true],
            ['lerato', 'BRAAI50', 'fixed', 50, 100, null, true],
            ['johan', 'VELD15', 'percent', 15, null, null, true],
            ['johan', 'SUMMER20', 'percent', 20, null, now()->subDays(30), true],
            ['zanele', 'WELCOME10', 'percent', 10, null, null, true],
            ['zanele', 'GLOW25', 'percent', 25, 5, null, true],
            ['kabelo', 'TECH100', 'fixed', 100, null, now()->addDays(45), true],
            ['kabelo', 'OLDCODE', 'percent', 5, null, null, false],
        ];

        foreach ($rows as [$owner, $code, $type, $value, $max, $expires, $active]) {
            PromoCode::create([
                'store_id' => $this->stores[$owner]->id,
                'code' => $code,
                'discount_type' => $type,
                'discount_value' => $value,
                'max_uses' => $max,
                'used_count' => 0,
                'expires_at' => $expires,
                'is_active' => $active,
            ]);
        }

        // GLOW25 has a use limit of 5, all of it used: shows the "fully redeemed" state.
        PromoCode::query()->where('code', 'GLOW25')->update(['used_count' => 5]);
    }

    // ----------------------------------------------------------------- orders

    private function makeOrders(): void
    {
        $this->payable(fn () => $this->orderScenarios());
    }

    /**
     * Runs $callback with a (fake, test-mode) Paystack key set so checkout flags
     * orders as needing payment, then puts the configuration back.
     */
    private function payable(callable $callback, bool $configured = true): void
    {
        $previous = config('paystack.secret_key');
        config(['paystack.secret_key' => $configured ? 'sk_test_demoseeddata0000' : '']);

        try {
            $callback();
        } finally {
            config(['paystack.secret_key' => $previous]);
        }
    }

    private function orderScenarios(): void
    {
        $p = fn (string $name) => $this->products[$name];

        // 1. Paid and delivered, no promo.
        $o = $this->placeOrder('thandi', [[$p('Cast Iron Potjie Pot 3L'), 1], [$p('Beeswax Taper Candles, 6 Pack'), 2]], null, 52);
        $this->pay($o, 52);
        $this->advanceAll($o, OrderStatus::Delivered, 48);

        // 2. Paid and delivered, one store.
        $o = $this->placeOrder('pieter', [[$p('Alpine 2-Person Dome Tent'), 1], [$p('LED Headlamp 400 Lumen'), 1], [$p('Insulated Steel Bottle 750ml'), 2]], 'VELD15', 47);
        $this->pay($o, 47);
        $this->advanceAll($o, OrderStatus::Delivered, 42);

        // 3. Paid and delivered with a percentage promo.
        $o = $this->placeOrder('ayesha', [[$p('Marula Facial Oil 30ml'), 1], [$p('Rooibos Calming Face Mask'), 1], [$p('Rooibos Tea, 80 Bags'), 2]], 'WELCOME10', 40);
        $this->pay($o, 40);
        $this->advanceAll($o, OrderStatus::Delivered, 36);

        // 4. Paid and delivered, electronics.
        $o = $this->placeOrder('naledi', [[$p('Wireless Over-Ear Headphones'), 1], [$p('65W USB-C Fast Charger'), 1], [$p('A5 Dotted Notebook'), 2]], null, 33);
        $this->pay($o, 33);
        $this->advanceAll($o, OrderStatus::Delivered, 29);

        // 5. The multi-seller order: four stores, four different line statuses.
        $o = $this->placeOrder('thandi', [
            [$p('Enamel Camping Mug, Set of 4'), 1],
            [$p('20L Daypack'), 1],
            [$p('Shea Butter Body Cream 250ml'), 2],
            [$p('Braided USB-C Cable 2m'), 1],
        ], null, 28);
        $this->pay($o, 28);
        $this->advanceStore($o, 'Karoo Home & Hearth', OrderStatus::Delivered, 24);
        $this->advanceStore($o, 'Veld & Vine Outdoors', OrderStatus::Shipped, 23);
        $this->advanceStore($o, 'Ubuntu Beauty Co.', OrderStatus::Processing, 26);

        // 6. Shipped.
        $o = $this->placeOrder('sipho', [[$p('Sleeping Bag, Comfort -5C'), 1], [$p('Inflatable Sleeping Mat'), 1]], null, 21);
        $this->pay($o, 21);
        $this->advanceAll($o, OrderStatus::Shipped, 19);

        // 7. Paid, being packed, fixed-amount promo.
        $o = $this->placeOrder('pieter', [[$p('Cast Iron Skillet 26cm'), 1], [$p('Olive Wood Serving Board'), 1]], 'BRAAI50', 17);
        $this->pay($o, 17);
        $this->advanceAll($o, OrderStatus::Processing, 16);

        // 8. Partially cancelled: one earbuds line cancelled after payment, partial refund.
        $o = $this->placeOrder('naledi', [[$p('Portable Bluetooth Speaker'), 1], [$p('True Wireless Earbuds'), 1]], null, 14);
        $this->pay($o, 14);
        $this->cancelLine($o, 'True Wireless Earbuds', 13);

        // 9. Fully cancelled and refunded.
        $o = $this->placeOrder('ayesha', [[$p('Gas Camping Stove'), 1], [$p('Camp Cookset, 8 Piece'), 1]], null, 12);
        $this->pay($o, 12);
        foreach ($o->items()->get() as $line) {
            $this->cancelLine($o, $line->product_name, 11);
        }

        // 10. Awaiting payment (the buyer left Paystack without paying).
        $o = $this->placeOrder('sipho', [[$p('Cotton Throw Blanket, Sand'), 1], [$p('Woven Seagrass Basket'), 1]], null, 1);
        $this->openAttempt($o, PaymentAttemptStatus::Pending, PaymentStatus::Pending);

        // 11. Payment failed (declined card).
        $o = $this->placeOrder('thandi', [[$p('Mechanical Keyboard 75%'), 1], [$p('Monitor Arm, Single'), 1]], null, 1);
        $this->openAttempt($o, PaymentAttemptStatus::Failed, PaymentStatus::Failed);

        // 12. Unpaid order the buyer gave up on (cancelled before payment).
        $o = $this->placeOrder('naledi', [[$p('Baobab Hand Cream 75ml'), 2], [$p('Lip Balm, Set of 3'), 1]], null, 6);
        $this->openAttempt($o, PaymentAttemptStatus::Pending, PaymentStatus::Pending);
        app(OrderLifecycle::class)->cancelUnpaidOrder($o->fresh());
        $o->refresh()->user->notify(new UnpaidOrderCancelledNotification($o));

        // 14. Just placed and paid, two stores, nothing packed yet.
        $o = $this->placeOrder('ayesha', [[$p('Fynbos Soy Candle'), 1], [$p('Charcoal Soap Bar'), 3]], null, 0);
        $this->pay($o, 0);

        // 13. Placed before card payments existed: pending and unpaid, never gated.
        $this->payable(function () use ($p) {
            $this->placeOrder('pieter', [[$p('Microfibre Travel Towel'), 1]], null, 3);
        }, false);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $lines
     */
    private function placeOrder(string $buyerKey, array $lines, ?string $promo, int $daysAgo): Order
    {
        $buyer = $this->users[$buyerKey];
        $def = $this->userDefinitions()[$buyerKey];

        foreach ($lines as [$product, $quantity]) {
            CartItem::query()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        }

        $order = app(CheckoutService::class)->place($buyer, [
            'shipping_name' => $buyer->name,
            'shipping_address' => $def['address'],
            'shipping_phone' => $def['phone'],
            'promo_code' => $promo,
        ], (string) Str::uuid(), '127.0.0.1');

        $when = now()->subDays($daysAgo)->subHours($daysAgo === 0 ? 2 : random_int(0, 8));
        $this->backdate($order, $when);

        return $order->refresh();
    }

    private function backdate(Order $order, Carbon $when): void
    {
        $order->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
        DB::table('order_items')->where('order_id', $order->id)->update(['created_at' => $when, 'updated_at' => $when]);
    }

    /**
     * An order paid through the real PaymentService: a pending attempt, then the
     * same confirmation call the Paystack webhook makes.
     */
    private function pay(Order $order, int $daysAgo): void
    {
        $payment = $this->attempt($order, PaymentAttemptStatus::Pending);
        $order->forceFill(['payment_status' => PaymentStatus::Pending])->saveQuietly();

        app(PaymentService::class)->recordSuccessfulCharge(
            $payment->reference,
            $payment->amount_cents,
            $payment->currency,
            (string) random_int(3_000_000_000, 3_999_999_999),
            ['status' => 'success', 'channel' => 'card', 'currency' => $payment->currency],
            'webhook',
        );

        $paidAt = Carbon::parse($order->created_at)->addMinutes(random_int(2, 9));
        $order->refresh()->forceFill(['paid_at' => $paidAt])->saveQuietly();
        $payment->refresh()->forceFill(['paid_at' => $paidAt, 'created_at' => $order->created_at])->saveQuietly();
    }

    private function attempt(Order $order, PaymentAttemptStatus $status): Payment
    {
        $reference = 'EM'.strtoupper(Str::random(14));

        return Payment::create([
            'order_id' => $order->id,
            'provider' => 'paystack',
            'reference' => $reference,
            'amount_cents' => $order->totalCents(),
            'currency' => $order->currencyCode(),
            'status' => $status,
            'authorization_url' => 'https://checkout.paystack.com/'.Str::lower(Str::random(12)),
        ]);
    }

    private function openAttempt(Order $order, PaymentAttemptStatus $attempt, PaymentStatus $orderStatus): void
    {
        $this->attempt($order, $attempt);
        $order->forceFill(['payment_status' => $orderStatus])->saveQuietly();
    }

    private function advanceAll(Order $order, OrderStatus $to, int $daysAgo): void
    {
        foreach ($order->items()->get() as $line) {
            $this->advance($line, $to, $daysAgo);
        }
    }

    private function advanceStore(Order $order, string $storeName, OrderStatus $to, int $daysAgo): void
    {
        foreach ($order->items()->where('store_name', $storeName)->get() as $line) {
            $this->advance($line, $to, $daysAgo);
        }
    }

    private function advance(OrderItem $line, OrderStatus $to, int $daysAgo): void
    {
        $lifecycle = app(OrderLifecycle::class);

        if ($to === OrderStatus::Delivered) {
            // Shipped on the way, so the history reads like real fulfilment.
            $lifecycle->advanceItem($line, OrderStatus::Shipped);
        }

        $line = $lifecycle->advanceItem($line->refresh(), $to);
        $line->order->refresh();
        $line->order->user->notify(new OrderItemStatusUpdatedNotification($line));

        $when = now()->subDays($daysAgo);
        DB::table('order_items')->where('id', $line->id)->update(['updated_at' => $when]);
        DB::table('orders')->where('id', $line->order_id)->update(['updated_at' => $when]);
    }

    private function cancelLine(Order $order, string $productName, int $daysAgo): void
    {
        $line = $order->items()->where('product_name', $productName)->firstOrFail();
        $line = app(OrderLifecycle::class)->cancelItem($line);
        $line->order->user->notify(new OrderLineCancelledNotification($line));

        $when = now()->subDays($daysAgo);
        DB::table('order_items')->where('id', $line->id)->update(['updated_at' => $when]);
        DB::table('orders')->where('id', $line->order_id)->update(['updated_at' => $when]);
    }

    // ---------------------------------------------------------------- reviews

    private function makeReviews(): void
    {
        $service = app(ReviewService::class);

        // Verified: each of these buyers has a delivered line for the product.
        $verified = [
            ['thandi', 'Cast Iron Potjie Pot 3L', 5, 'Seasoned beautifully after the first use. Heavy, properly made, and the lid fits like it was cast for it. We made oxtail and it was gone in an hour.', 44],
            ['thandi', 'Beeswax Taper Candles, 6 Pack', 4, 'Burn slowly and smell faintly of honey. A couple were slightly bent in the box but they straightened in warm hands.', 44],
            ['thandi', 'Enamel Camping Mug, Set of 4', 5, 'Exactly what I wanted. Light, rolled rims, no chips yet after a month of mornings.', 20],
            ['pieter', 'Alpine 2-Person Dome Tent', 5, 'Pitched in ten minutes alone in the Cederberg and held through a proper south-easter. Packs smaller than I expected.', 38],
            ['pieter', 'LED Headlamp 400 Lumen', 4, 'Bright and the red mode is a thoughtful touch. The strap could be a little longer for a beanie.', 38],
            ['pieter', 'Insulated Steel Bottle 750ml', 3, 'Keeps coffee hot but the lid gasket smelled of rubber for the first week.', 37],
            ['ayesha', 'Marula Facial Oil 30ml', 5, 'Absorbs fast and my skin has been calmer since the change of season. The dropper is easy to control.', 33],
            ['ayesha', 'Rooibos Calming Face Mask', 4, 'Cooling and not drying. I would like a bigger tub.', 33],
            ['ayesha', 'Rooibos Tea, 80 Bags', 5, 'The best rooibos I have had off a shelf. Rich, no bitterness.', 32],
            ['naledi', 'Wireless Over-Ear Headphones', 4, 'Noise cancelling is good on the taxi rank, less so on a plane. Battery is as promised.', 25],
            ['naledi', '65W USB-C Fast Charger', 5, 'Charges my laptop and phone at the same time without getting hot.', 25],
            ['naledi', 'A5 Dotted Notebook', 3, 'Paper is lovely but the cover marks easily.', 24],
        ];

        foreach ($verified as [$buyer, $product, $rating, $body, $daysAgo]) {
            $product = $this->products[$product];
            $review = $service->submit($this->users[$buyer], $product, $rating, $body);
            $review->forceFill(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)])->saveQuietly();
            $this->products[$product->name]->store->owner->notify(new NewReviewNotification($review));
        }

        // Unverified: written by people with no delivered order for the product. The
        // application refuses these today, so they are inserted directly, the way a
        // legacy row or a data import would arrive. They show how the storefront treats them.
        $unverified = [
            ['sipho', 'Alpine 2-Person Dome Tent', 4, 'Borrowed a friend\'s and liked it enough to put one on my list.', 16],
            ['ayesha', 'Wireless Over-Ear Headphones', 5, 'Great sound. My brother has these and I am jealous.', 12],
            ['sipho', 'Cast Iron Potjie Pot 3L', 2, 'Seems overpriced for what it is.', 8],
        ];

        foreach ($unverified as [$buyer, $product, $rating, $body, $daysAgo]) {
            Review::create([
                'product_id' => $this->products[$product]->id,
                'user_id' => $this->users[$buyer]->id,
                'rating' => $rating,
                'body' => $body,
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ]);
        }

        foreach (array_unique(array_column($unverified, 1)) as $name) {
            $stats = Review::query()->where('product_id', $this->products[$name]->id)
                ->selectRaw('COUNT(*) as c, AVG(rating) as a')->first();
            Product::query()->where('id', $this->products[$name]->id)
                ->update(['rating_count' => (int) $stats->c, 'rating_average' => round((float) $stats->a, 2)]);
        }
    }

    private function makeExtraNotifications(): void
    {
        // Marks a few as read so the bell shows a believable mix of read and unread.
        $buyerIds = $this->buyerIds();
        $sellerIds = collect(['lerato', 'johan', 'zanele', 'kabelo'])->map(fn ($k) => $this->users[$k]->id);

        $ids = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', array_merge($buyerIds, $sellerIds->all()))
            ->orderBy('created_at')
            ->pluck('id');

        $ids->take((int) floor($ids->count() * 0.6))->each(fn ($id) => DB::table('notifications')->where('id', $id)->update(['read_at' => now()->subDays(random_int(1, 5))]));
    }

    private function backdateNotifications(): void
    {
        $rows = DB::table('notifications')->get(['id', 'data', 'created_at']);

        foreach ($rows as $row) {
            $data = json_decode($row->data, true) ?: [];
            $orderId = $data['order_id'] ?? null;

            if ($orderId === null) {
                continue;
            }

            $order = DB::table('orders')->where('id', $orderId)->first(['created_at', 'updated_at']);

            if ($order) {
                $when = Carbon::parse($order->updated_at)->addMinutes(random_int(1, 40));
                DB::table('notifications')->where('id', $row->id)->update(['created_at' => $when, 'updated_at' => $when]);
            }
        }
    }

    // ------------------------------------------------------------------ images

    private function hsl(float $h, float $s, float $l): array
    {
        $h = fmod(($h % 360 + 360), 360) / 360;
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);

            return match (true) {
                $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
                $t < 1 / 2 => $q,
                $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
                default => $p,
            };
        };

        return [(int) round($f($h + 1 / 3) * 255), (int) round($f($h) * 255), (int) round($f($h - 1 / 3) * 255)];
    }

    private function color(GdImage $im, float $h, float $s, float $l, int $alpha = 0): int
    {
        [$r, $g, $b] = $this->hsl($h, $s, $l);

        return imagecolorallocatealpha($im, $r, $g, $b, $alpha);
    }

    private function rrect(GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $c): void
    {
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $c);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $c);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $c);
        }
    }

    /**
     * A neutral, product-like studio shot: soft backdrop, floor shadow, one
     * simple object in the listing's colour. Variants 1 and 2 are a mirrored
     * and a close-up crop so a gallery has different frames.
     */
    private function renderProduct(string $shape, int $hue, int $variant, int $seed): string
    {
        $s = self::SIZE;
        $im = imagecreatetruecolor($s, $s);
        imageantialias($im, true);
        imagealphablending($im, true);

        // Backdrop
        $topL = [0.94, 0.90, 0.86][$variant % 3];
        $botL = [0.84, 0.78, 0.9][$variant % 3];
        $bgHue = $hue + ($seed % 17) - 8 + $variant * 12;

        for ($y = 0; $y < $s; $y++) {
            $t = $y / $s;
            $l = $topL + ($botL - $topL) * $t;
            imageline($im, 0, $y, $s, $y, $this->color($im, $bgHue, 0.22, $l));
        }

        // Floor shadow
        imagefilledellipse($im, 400, 648, 420, 46, $this->color($im, $bgHue, 0.3, 0.3, 100));
        imagefilledellipse($im, 400, 646, 340, 30, $this->color($im, $bgHue, 0.3, 0.25, 90));

        $main = $this->color($im, $hue, 0.5, 0.5);
        $light = $this->color($im, $hue, 0.5, 0.64);
        $dark = $this->color($im, $hue, 0.5, 0.36);
        $pale = $this->color($im, $hue, 0.25, 0.92);
        $ink = $this->color($im, $hue, 0.15, 0.2);

        match ($shape) {
            'mug' => $this->drawMug($im, $main, $light, $dark),
            'bottle' => $this->drawBottle($im, $main, $light, $dark, $pale),
            'box' => $this->drawBox($im, $main, $light, $dark),
            'jar' => $this->drawJar($im, $main, $light, $dark, $pale),
            'pot' => $this->drawPot($im, $main, $light, $dark),
            'bowl' => $this->drawBowl($im, $main, $light, $dark),
            'bag' => $this->drawBag($im, $main, $light, $dark),
            'book' => $this->drawBook($im, $main, $light, $dark, $pale),
            'tent' => $this->drawTent($im, $main, $light, $dark),
            'device' => $this->drawDevice($im, $ink, $light, $pale),
            'headphones' => $this->drawHeadphones($im, $main, $dark, $ink),
            'candle' => $this->drawCandle($im, $pale, $dark, $hue),
            'vase' => $this->drawVase($im, $main, $light, $dark),
            'tub' => $this->drawTub($im, $main, $light, $dark, $pale),
            'cloth' => $this->drawCloth($im, $main, $light, $dark),
            'basket' => $this->drawBowl($im, $main, $light, $dark),
            'board' => $this->drawBoard($im, $main, $light, $dark),
            default => $this->drawBox($im, $main, $light, $dark),
        };

        if ($variant === 1) {
            imageflip($im, IMG_FLIP_HORIZONTAL);
        }

        if ($variant === 2) {
            $cropped = imagecrop($im, ['x' => 100, 'y' => 130, 'width' => 600, 'height' => 600]);
            if ($cropped !== false) {
                $scaled = imagescale($cropped, $s, $s, IMG_BICUBIC);
                if ($scaled !== false) {
                    $im = $scaled;
                }
            }
        }

        ob_start();
        imagejpeg($im, null, 86);

        return (string) ob_get_clean();
    }

    private function drawMug(GdImage $im, int $main, int $light, int $dark): void
    {
        imagesetthickness($im, 30);
        imagearc($im, 520, 470, 170, 210, 270, 90, $dark);
        imagesetthickness($im, 1);
        $this->rrect($im, 270, 320, 500, 640, 28, $main);
        imagefilledrectangle($im, 292, 350, 322, 610, $light);
        imagefilledellipse($im, 385, 322, 232, 46, $dark);
        imagefilledellipse($im, 385, 322, 196, 32, $light);
    }

    private function drawBottle(GdImage $im, int $main, int $light, int $dark, int $pale): void
    {
        $this->rrect($im, 300, 300, 500, 640, 34, $main);
        $this->rrect($im, 355, 190, 445, 320, 12, $main);
        $this->rrect($im, 340, 130, 460, 200, 14, $dark);
        imagefilledrectangle($im, 322, 330, 346, 610, $light);
        imagefilledrectangle($im, 300, 430, 500, 540, $pale);
        imagefilledrectangle($im, 330, 462, 470, 472, $dark);
        imagefilledrectangle($im, 330, 490, 430, 498, $light);
    }

    private function drawBox(GdImage $im, int $main, int $light, int $dark): void
    {
        imagefilledpolygon($im, [230, 360, 540, 360, 540, 640, 230, 640], $main);
        imagefilledpolygon($im, [230, 360, 340, 285, 650, 285, 540, 360], $light);
        imagefilledpolygon($im, [540, 360, 650, 285, 650, 565, 540, 640], $dark);
        imagefilledrectangle($im, 360, 360, 410, 640, $light);
    }

    private function drawJar(GdImage $im, int $main, int $light, int $dark, int $pale): void
    {
        $this->rrect($im, 280, 290, 520, 640, 40, $main);
        $this->rrect($im, 300, 220, 500, 300, 14, $dark);
        imagefilledrectangle($im, 300, 400, 500, 540, $pale);
        imagefilledrectangle($im, 330, 440, 470, 452, $dark);
        imagefilledrectangle($im, 330, 475, 420, 483, $light);
        imagefilledrectangle($im, 302, 320, 322, 610, $light);
    }

    private function drawPot(GdImage $im, int $main, int $light, int $dark): void
    {
        $this->rrect($im, 150, 400, 220, 440, 14, $dark);
        $this->rrect($im, 580, 400, 650, 440, 14, $dark);
        imagefilledarc($im, 400, 420, 460, 440, 0, 180, $main, IMG_ARC_PIE);
        imagefilledarc($im, 400, 420, 440, 140, 180, 360, $dark, IMG_ARC_PIE);
        imagefilledellipse($im, 400, 290, 46, 46, $dark);
        imagefilledrectangle($im, 270, 410, 292, 600, $light);
        foreach ([300, 500] as $x) {
            imagefilledpolygon($im, [$x, 620, $x + 40, 620, $x + 30, 660, $x + 10, 660], $dark);
        }
    }

    private function drawBowl(GdImage $im, int $main, int $light, int $dark): void
    {
        imagefilledarc($im, 400, 400, 520, 470, 0, 180, $main, IMG_ARC_PIE);
        imagefilledellipse($im, 400, 400, 520, 60, $dark);
        imagefilledellipse($im, 400, 400, 480, 40, $light);
        imagefilledrectangle($im, 330, 640, 470, 650, $dark);
    }

    private function drawBag(GdImage $im, int $main, int $light, int $dark): void
    {
        imagesetthickness($im, 20);
        imagearc($im, 400, 330, 200, 250, 180, 360, $dark);
        imagesetthickness($im, 1);
        imagefilledpolygon($im, [250, 640, 550, 640, 520, 330, 280, 330], $main);
        $this->rrect($im, 270, 330, 530, 440, 18, $dark);
        $this->rrect($im, 320, 500, 480, 590, 14, $light);
        imagefilledrectangle($im, 388, 430, 412, 470, $light);
    }

    private function drawBook(GdImage $im, int $main, int $light, int $dark, int $pale): void
    {
        $this->rrect($im, 270, 190, 530, 640, 10, $main);
        imagefilledrectangle($im, 270, 190, 304, 640, $dark);
        imagefilledrectangle($im, 524, 205, 545, 625, $pale);
        imagefilledrectangle($im, 340, 280, 480, 296, $light);
        imagefilledrectangle($im, 340, 316, 440, 326, $light);
    }

    private function drawTent(GdImage $im, int $main, int $light, int $dark): void
    {
        imagefilledpolygon($im, [400, 220, 650, 640, 150, 640], $main);
        imagefilledpolygon($im, [400, 220, 650, 640, 520, 640], $dark);
        imagefilledpolygon($im, [400, 340, 480, 640, 320, 640], $light);
        imageline($im, 400, 220, 400, 340, $dark);
        imagefilledrectangle($im, 120, 640, 680, 652, $dark);
    }

    private function drawDevice(GdImage $im, int $ink, int $light, int $pale): void
    {
        $this->rrect($im, 290, 190, 510, 640, 36, $ink);
        $this->rrect($im, 312, 220, 488, 520, 16, $light);
        imagefilledellipse($im, 400, 580, 44, 44, $pale);
        imagefilledrectangle($im, 340, 260, 460, 272, $pale);
        imagefilledrectangle($im, 340, 296, 420, 306, $pale);
    }

    private function drawHeadphones(GdImage $im, int $main, int $dark, int $ink): void
    {
        imagesetthickness($im, 34);
        imagearc($im, 400, 420, 380, 460, 180, 360, $ink);
        imagesetthickness($im, 1);
        foreach ([240, 560] as $x) {
            imagefilledellipse($im, $x, 450, 130, 220, $main);
            imagefilledellipse($im, $x + ($x < 400 ? 20 : -20), 450, 80, 170, $dark);
        }
    }

    private function drawCandle(GdImage $im, int $pale, int $dark, int $hue): void
    {
        $this->rrect($im, 330, 290, 470, 640, 14, $pale);
        imagefilledrectangle($im, 330, 500, 470, 640, $this->color($im, $hue, 0.3, 0.8));
        imagefilledrectangle($im, 398, 255, 402, 292, $dark);
        imagefilledellipse($im, 400, 232, 42, 86, $this->color($im, 38, 0.95, 0.58));
        imagefilledellipse($im, 400, 242, 20, 48, $this->color($im, 48, 0.95, 0.78));
    }

    private function drawVase(GdImage $im, int $main, int $light, int $dark): void
    {
        imagefilledellipse($im, 400, 500, 310, 290, $main);
        $this->rrect($im, 360, 250, 440, 400, 8, $main);
        imagefilledellipse($im, 400, 252, 130, 34, $dark);
        imagefilledellipse($im, 335, 480, 50, 140, $light);
    }

    private function drawTub(GdImage $im, int $main, int $light, int $dark, int $pale): void
    {
        $this->rrect($im, 260, 440, 540, 640, 26, $main);
        $this->rrect($im, 245, 380, 555, 456, 18, $dark);
        imagefilledrectangle($im, 300, 500, 500, 590, $pale);
        imagefilledrectangle($im, 330, 530, 470, 540, $dark);
        imagefilledrectangle($im, 276, 470, 296, 620, $light);
    }

    private function drawCloth(GdImage $im, int $main, int $light, int $dark): void
    {
        imagefilledpolygon($im, [220, 330, 580, 330, 600, 630, 200, 630], $main);
        foreach ([380, 440, 500, 560] as $y) {
            imagefilledrectangle($im, 214, $y, 590, $y + 14, $light);
        }
        imagefilledpolygon($im, [220, 330, 580, 330, 570, 360, 230, 360], $dark);
    }

    private function drawBoard(GdImage $im, int $main, int $light, int $dark): void
    {
        $this->rrect($im, 200, 330, 600, 590, 40, $main);
        imagefilledellipse($im, 540, 460, 54, 54, $dark);
        foreach ([380, 430, 480, 530] as $y) {
            imageline($im, 240, $y, 500, $y + 6, $light);
        }
    }

    /**
     * A flat square mark for a store, with no text: a hearth, mountains, a leaf or nested squares.
     */
    private function renderLogo(string $glyph, int $hue): string
    {
        $s = 320;
        $im = imagecreatetruecolor($s, $s);
        imageantialias($im, true);
        imagefill($im, 0, 0, $this->color($im, $hue, 0.45, 0.4));
        $white = $this->color($im, $hue, 0.2, 0.97);
        $soft = $this->color($im, $hue, 0.4, 0.62);

        switch ($glyph) {
            case 'hearth':
                imagefilledpolygon($im, [160, 70, 260, 150, 60, 150], $white);
                imagefilledrectangle($im, 90, 150, 230, 250, $white);
                imagefilledellipse($im, 160, 210, 54, 70, $soft);
                break;
            case 'mountain':
                imagefilledpolygon($im, [40, 250, 130, 100, 220, 250], $white);
                imagefilledpolygon($im, [140, 250, 220, 140, 290, 250], $soft);
                imagefilledellipse($im, 235, 85, 44, 44, $white);
                break;
            case 'leaf':
                imagefilledellipse($im, 160, 160, 150, 220, $white);
                imageline($im, 160, 70, 160, 270, $soft);
                imagesetthickness($im, 6);
                imageline($im, 160, 270, 160, 70, $soft);
                break;
            default:
                imagefilledrectangle($im, 70, 70, 250, 250, $white);
                imagefilledrectangle($im, 105, 105, 215, 215, $soft);
                imagefilledrectangle($im, 138, 138, 182, 182, $white);
        }

        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }
}
