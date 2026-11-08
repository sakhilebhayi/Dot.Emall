<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreStatus;
use App\Models\Address;
use App\Models\Brand;
use App\Models\Collection;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\ReturnRequest;
use App\Models\Review;
use App\Models\Shipment;
use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NewReviewNotification;
use App\Notifications\UnpaidOrderCancelledNotification;
use App\Services\Addresses\AddressBook;
use App\Services\Addresses\AddressRules;
use App\Services\Cart\CartManager;
use App\Services\Catalogue\ProductAttributes;
use App\Services\Catalogue\ProductVariants;
use App\Services\CheckoutService;
use App\Services\Community\ContentReports;
use App\Services\Community\ReviewResponses;
use App\Services\Community\ReviewVotes;
use App\Services\Community\StoreRatings;
use App\Services\Guests\GuestOrderAccess;
use App\Services\Ledger\LedgerService;
use App\Services\OrderLifecycle;
use App\Services\PaymentService;
use App\Services\Returns\ReturnService;
use App\Services\ReviewService;
use App\Services\Search\SearchIndexer;
use App\Services\Search\Vocabulary;
use App\Services\Shipping\ShipmentService;
use App\Support\Actor;
use GdImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A believable, fictional South African marketplace for design, audit and demo work (docs/R43_DEMO.md).
 *
 * Everything is built through the real models and services (CheckoutService, PaymentService, OrderLifecycle,
 * ShipmentService, ReturnService, ReviewService, the discovery services...), so totals, snapshots, stock, shipping,
 * tracking, returns, the ledger, ratings and the search index are exactly what the live code produces. History is
 * made by moving the clock (Carbon::setTestNow) while a service runs, so every timestamp the services write is
 * honestly in the past instead of being patched afterwards. The only rows written directly are the Paystack facts
 * that normally arrive from Paystack (payment attempts), a handful of unverified legacy reviews the application
 * itself would refuse to create, and the price history of the products that had a genuine earlier price.
 *
 * Never runs in production. Every demo account uses an @emall-demo.test address, which is how purge() finds its own
 * data again. Password for every demo account: "password".
 */
class EmallDemoSeeder extends Seeder
{
    public const DOMAIN = 'emall-demo.test';

    public const PASSWORD = 'password';

    public const GUEST_EMAIL = 'guest.shopper@emall-demo.test';

    private const SIZE = 800;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Store> */
    private array $stores = [];

    /** @var array<string, StoreCategory> */
    private array $categories = [];

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, array<string, ShippingMethod>> store key => method name => method */
    private array $methods = [];

    /** @var array<string, Address> buyer key => default shipping address */
    private array $addresses = [];

    private Carbon $base;

    private ?string $guestLink = null;

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

        // While seeding: no e-mail leaves the machine, the search index is built once at the end instead of after every
        // save, and the demo shows a ledger with a visible platform fee (an assumption of the demo, not a business term).
        $previous = [config('mail.default'), config('discovery.search.observe'), config('emall.commission.percent')];
        config(['mail.default' => 'array', 'discovery.search.observe' => false, 'emall.commission.percent' => 5.0]);
        $this->base = Carbon::now();

        try {
            $this->makeUsers();
            $this->makeCategories();
            $this->makeStores();
            $this->makeShipping();
            $this->makeAddresses();
            $this->makeProducts();
            $this->makePromoCodes();
            $this->makeOrders();
            $this->makeReviews();
            (new EmallDemoEngagement($this, $this->users, $this->products, fn (float $days, callable $fn, int $hour = 10) => $this->at($days, $fn, $hour)))->run();
            $this->guestLink = $this->makeGuestOrders();
        } finally {
            Carbon::setTestNow();
            config(['mail.default' => $previous[0], 'discovery.search.observe' => $previous[1], 'emall.commission.percent' => $previous[2]]);
        }

        $this->finish();

        $this->command?->info(sprintf(
            'Demo marketplace ready: %d users, %d stores, %d products, %d orders, %d reviews. Password for every account: %s',
            count($this->users), count($this->stores), count($this->products),
            Order::query()->whereIn('user_id', $this->buyerIds())->count() + Order::query()->where('guest_email', self::GUEST_EMAIL)->count(),
            Review::query()->whereIn('product_id', collect($this->products)->pluck('id'))->count(),
            self::PASSWORD,
        ));

        if ($this->guestLink !== null) {
            $this->command?->line('Guest order link (no sign-in needed): '.$this->guestLink);
        }
    }

    public function alreadySeeded(): bool
    {
        return User::query()->where('email', 'like', '%@'.self::DOMAIN)->exists();
    }

    /**
     * Runs $fn with the clock set to a moment $daysAgo days before the seeding started, so whatever the services stamp
     * is in the past. The clock goes back to "now" afterwards.
     */
    private function at(float $daysAgo, callable $fn, int $hour = 10): mixed
    {
        $when = $this->base->copy()->subDays((int) floor($daysAgo))->setTime($hour, random_int(0, 50), random_int(0, 59));

        if ($when->gt($this->base)) {
            $when = $this->base->copy()->subMinutes(random_int(20, 90));
        }

        Carbon::setTestNow($when);

        try {
            return $fn();
        } finally {
            Carbon::setTestNow($this->base);
        }
    }

    /**
     * Removes every row (and file) the seeder made, and nothing else. The shared category tree is not the seeder's.
     */
    public function purge(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The Dot.Emall demo seeder never runs in production.');
        }

        $hashes = [];

        DB::transaction(function () use (&$hashes) {
            $userIds = User::query()->where('email', 'like', '%@'.self::DOMAIN)->pluck('id');

            // Behaviour is stored under keyed hashes, never user ids: recompute the demo people's and visitors'.
            foreach ($userIds as $id) {
                $hashes[] = Actor::forUser($id)->hash();
            }

            foreach (range(1, EmallDemoEngagement::VISITORS) as $n) {
                $hashes[] = Actor::forToken(EmallDemoEngagement::token($n))->hash();
            }

            DB::table('search_queries')->whereIn('actor_hash', $hashes)->delete();
            DB::table('product_views')->whereIn('actor_hash', $hashes)->delete();
            DB::table('commerce_events')->whereIn('actor_hash', $hashes)->delete();

            if ($userIds->isNotEmpty()) {
                $storeIds = Store::query()->whereIn('owner_id', $userIds)->pluck('id');
                $orderIds = Order::query()->whereIn('user_id', $userIds)->orWhere('guest_email', 'like', '%@'.self::DOMAIN)->pluck('id');
                DB::table('commerce_events')->whereIn('order_id', $orderIds)->delete(); // a guest's order is recorded under a token of its own

                DB::table('payment_events')->whereIn('order_id', $orderIds)->delete();
                DB::table('refunds')->whereIn('order_id', $orderIds)->delete();
                DB::table('payments')->whereIn('order_id', $orderIds)->delete();
                // Since D-98 the keys from money records to users RESTRICT: everything the demo people own goes first, then the people.
                DB::table('return_requests')->where(fn ($q) => $q->whereIn('requester_id', $userIds)->orWhereIn('seller_id', $userIds)->orWhereIn('order_id', $orderIds))->delete();
                DB::table('ledger_entries')->where(fn ($q) => $q->whereIn('seller_id', $userIds)->orWhereIn('order_id', $orderIds))->delete();
                DB::table('seller_order_notes')->whereIn('user_id', $userIds)->delete();
                DB::table('content_reports')->whereIn('reporter_id', $userIds)->delete();
                DB::table('review_responses')->whereIn('user_id', $userIds)->delete();
                DB::table('product_answers')->whereIn('user_id', $userIds)->delete();
                DB::table('product_questions')->whereIn('user_id', $userIds)->delete();
                DB::table('order_groups')->where(fn ($q) => $q->whereIn('seller_id', $userIds)->orWhereIn('order_id', $orderIds))->delete();
                DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
                DB::table('order_items')->whereIn('store_id', $storeIds)->delete();
                DB::table('orders')->whereIn('id', $orderIds)->delete();
                DB::table('cart_items')->whereIn('user_id', $userIds)->delete();
                DB::table('reviews')->whereIn('user_id', $userIds)->delete();
                DB::table('collections')->whereIn('curator_id', $userIds)->delete();

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

            // Brands the demo introduced go once nothing else sells under them.
            $names = collect(EmallDemoCatalogue::products())->flatten(1)->map(fn ($row) => $row[8]['brand'] ?? null)->filter()->unique()->all();
            Brand::query()->whereIn('name', $names)->whereDoesntHave('products')->get()->each->delete();
        });

        // The vocabulary (brands, categories, words) is rebuilt from what is left.
        app(SearchIndexer::class)->rebuildVocabulary();
        Vocabulary::forget();
    }

    // ------------------------------------------------------------------ people

    /**
     * @return array<string, array{name: string, role: string, phone: string}>
     */
    private function userDefinitions(): array
    {
        return [
            'thandi' => ['name' => 'Thandi Mokoena', 'role' => 'buyer', 'phone' => '+27 82 555 0143'],
            'pieter' => ['name' => 'Pieter van der Merwe', 'role' => 'buyer', 'phone' => '+27 83 555 0177'],
            'naledi' => ['name' => 'Naledi Dlamini', 'role' => 'buyer', 'phone' => '+27 71 555 0120'],
            'sipho' => ['name' => 'Sipho Khumalo', 'role' => 'buyer', 'phone' => '+27 60 555 0199'],
            'ayesha' => ['name' => 'Ayesha Patel', 'role' => 'buyer', 'phone' => '+27 72 555 0188'],
            'lerato' => ['name' => 'Lerato Mahlangu', 'role' => 'seller', 'phone' => ''],
            'johan' => ['name' => 'Johan Botha', 'role' => 'seller', 'phone' => ''],
            'zanele' => ['name' => 'Zanele Nkosi', 'role' => 'seller', 'phone' => ''],
            'kabelo' => ['name' => 'Kabelo Sithole', 'role' => 'seller', 'phone' => ''],
            'operator' => ['name' => 'Marcus Williams', 'role' => 'operator', 'phone' => ''],
            'nomsa' => ['name' => 'Nomsa Zulu', 'role' => 'new', 'phone' => ''],
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

    /** The shared category tree (about 130 categories with their spec definitions). Idempotent. */
    private function makeCategories(): void
    {
        Artisan::call('emall:seed-categories');

        $this->categories = StoreCategory::query()->get()->keyBy('slug')->all();
    }

    private function makeStores(): void
    {
        foreach (EmallDemoCatalogue::stores() as $owner => [$name, $description, $glyph, $hue, $profile]) {
            $slug = Str::slug($name).'-'.Str::lower(Str::random(6));
            $logo = 'store-logos/'.$slug.'.png';

            Storage::disk('public')->put($logo, $this->renderLogo($glyph, $hue));

            $user = $this->users[$owner];

            $this->stores[$owner] = Store::create([
                'owner_id' => $user->id,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'logo_path' => $logo,
                'is_active' => true,
                'status' => StoreStatus::Active,
                'region' => $profile['region'],
                'city' => $profile['city'],
                'legal_name' => $profile['legal_name'],
                'registration_number' => $profile['registration_number'],
                'vat_registered' => $profile['vat_registered'],
                'vat_number' => $profile['vat_number'],
                'contact_email' => $owner.'@'.self::DOMAIN,
                'contact_phone' => $profile['contact_phone'],
                'return_policy' => $profile['return_policy'],
                'shipping_policy' => $profile['shipping_policy'],
                'reviewed_by' => $this->users['operator']->id,
                'reviewed_at' => now()->subDays(random_int(90, 150)),
            ]);

            // The trust ladder shows only what was recorded: sign-in through InfoDot, and a linked InfoDot company.
            // (The sign-in time is the demo's own record that it happened; nothing here claims InfoDot verified anyone.)
            if ($profile['infodot'] !== false) {
                $user->forceFill(['infodot_signed_in_at' => now()->subDays(random_int(1, 20))])->save();
            }

            if ($profile['infodot'] === true) {
                $team = Team::query()->where('user_id', $user->id)->where('personal_team', true)->first();
                $team?->forceFill(['infodot_team_uuid' => (string) Str::uuid()])->save();
                $this->stores[$owner]->forceFill(['team_id' => $team?->id])->save();
            }
        }
    }

    /**
     * Every store sells a flat-rate delivery, a free-over-a-threshold delivery and collection; some deliver faster to
     * the provinces next door, and a rule price differs by province.
     */
    private function makeShipping(): void
    {
        $provinces = AddressRules::PROVINCES;

        $defs = [
            'lerato' => [
                ['The Courier Guy', 'flat', 85, null, 1, 2, 4, null, 'Packed in Graaff-Reinet and sent with The Courier Guy.', ['Gauteng' => 95, 'Western Cape' => 110, 'Eastern Cape' => 70, 'KwaZulu-Natal' => 95, 'Free State' => 90, 'Northern Cape' => 90, 'Limpopo' => 110, 'Mpumalanga' => 105, 'North West' => 105]],
                ['Free delivery over R1,500', 'free_over', 85, 1500, 1, 3, 5, null, 'Free on orders of R1,500 or more from this store, to the four big provinces.', ['Gauteng' => 95, 'Western Cape' => 110, 'Eastern Cape' => 70, 'KwaZulu-Natal' => 95]],
                ['Collect in Graaff-Reinet', 'collection', 0, null, 1, 0, 0, '12 Church Street, Graaff-Reinet, 6280', 'Ready the next working day. Bring your order number.', null],
            ],
            'johan' => [
                ['Aramex economy', 'flat', 65, null, 1, 3, 6, null, 'Road freight, three to six working days.', null],
                ['Aramex overnight bag', 'flat', 99, null, 1, 1, 2, null, 'Overnight to Gauteng and KwaZulu-Natal.', ['Gauteng' => 99, 'KwaZulu-Natal' => 89]],
                ['Free delivery over R2,000', 'free_over', 65, 2000, 1, 3, 6, null, 'Free economy delivery on orders of R2,000 or more from this store.', null],
                ['Collect from the shop in Underberg', 'collection', 0, null, 0, 0, 0, '3 Main Road, Underberg, 3257', 'Open Tuesday to Saturday, 08:00 to 16:00.', null],
            ],
            'zanele' => [
                ['Pargo pick-up point', 'flat', 49, null, 2, 2, 5, null, 'Choose a Pargo point near you at the courier step.', null],
                ['Free delivery over R600', 'free_over', 49, 600, 2, 2, 5, null, 'Free Pargo delivery on orders of R600 or more from this store.', null],
                ['Collect in Durban', 'collection', 0, null, 1, 0, 0, '24 Florida Road, Morningside, Durban, 4001', 'Weekdays 09:00 to 17:00.', null],
            ],
            'kabelo' => [
                ['Courier (next working day)', 'flat', 79, null, 0, 1, 3, null, 'Dispatched the same day for orders paid before 14:00.', ['Gauteng' => 69, 'Western Cape' => 99, 'KwaZulu-Natal' => 89, 'Eastern Cape' => 99, 'Free State' => 89, 'Limpopo' => 99, 'Mpumalanga' => 89, 'North West' => 89, 'Northern Cape' => 119]],
                ['Free delivery over R2,000', 'free_over', 79, 2000, 0, 1, 3, null, 'Free courier delivery on orders of R2,000 or more from this store.', null],
                ['Collect in Braamfontein', 'collection', 0, null, 0, 0, 0, '66 De Korte Street, Braamfontein, Johannesburg, 2017', 'Ready within two hours of payment, weekdays.', null],
            ],
        ];

        foreach ($defs as $owner => $rows) {
            foreach ($rows as $position => [$name, $type, $price, $freeOver, $handling, $min, $max, $address, $instructions, $regions]) {
                $method = ShippingMethod::create([
                    'store_id' => $this->stores[$owner]->id,
                    'name' => $name,
                    'type' => $type,
                    'price' => $price,
                    'free_over' => $freeOver,
                    'handling_days' => $handling,
                    'window_min_days' => $min,
                    'window_max_days' => $max,
                    'collection_address' => $address,
                    'instructions' => $instructions,
                    'is_active' => true,
                    'position' => $position,
                ]);

                foreach ($regions ?? [] as $province => $regionPrice) {
                    if (in_array($province, $provinces, true)) {
                        $method->regions()->create(['country' => 'ZA', 'province' => $province, 'price' => $regionPrice]);
                    }
                }

                $this->methods[$owner][$name] = $method;
            }
        }
    }

    private function makeAddresses(): void
    {
        $book = app(AddressBook::class);

        $defs = [
            'thandi' => [
                ['Home', '14 Jacaranda Avenue', 'Parkhurst', 'Johannesburg', 'Gauteng', '2193', null, true],
                ['Work', 'Building 3, 11 Alice Lane', 'Sandton', 'Johannesburg', 'Gauteng', '2196', 'Reception on the ground floor, weekdays 08:00 to 17:00.', false],
            ],
            'pieter' => [
                ['Home', '22 Kloof Street', 'Gardens', 'Cape Town', 'Western Cape', '8001', null, true],
                ['Holiday house', '9 Marine Drive', 'Hermanus', 'Hermanus', 'Western Cape', '7200', 'Gate code 4417.', false],
            ],
            'naledi' => [['Home', '7 Florida Road', 'Morningside', 'Durban', 'KwaZulu-Natal', '4001', null, true]],
            'sipho' => [['Home', '310 Church Street', 'Arcadia', 'Pretoria', 'Gauteng', '0083', null, true]],
            'ayesha' => [['Home', '5 Stamford Hill Road', 'Greyville', 'Durban', 'KwaZulu-Natal', '4023', null, true]],
        ];

        foreach ($defs as $key => $rows) {
            foreach ($rows as [$label, $line1, $suburb, $city, $province, $postal, $instructions, $default]) {
                $address = $book->add($this->users[$key], [
                    'label' => $label,
                    'recipient_name' => $this->users[$key]->name,
                    'phone' => $this->userDefinitions()[$key]['phone'],
                    'line1' => $line1,
                    'suburb' => $suburb,
                    'city' => $city,
                    'province' => $province,
                    'postal_code' => $postal,
                    'country' => 'ZA',
                    'instructions' => $instructions,
                    'is_default_shipping' => $default,
                ]);

                if ($default) {
                    $this->addresses[$key] = $address;
                }
            }
        }
    }

    private function makeProducts(): void
    {
        $i = 0;
        $variants = app(ProductVariants::class);
        $attributes = app(ProductAttributes::class);

        foreach (EmallDemoCatalogue::products() as $owner => $rows) {
            foreach ($rows as $row) {
                [$name, $category, $price, $compare, $stock, $shape, $hue, $description] = $row;
                $options = $row[8] ?? [];
                $flag = $options['flag'] ?? null;
                $matrix = $options['matrix'] ?? null;

                $product = Product::create([
                    'store_id' => $this->stores[$owner]->id,
                    'category_id' => $this->categories[$category]->id,
                    'brand_id' => isset($options['brand']) ? Brand::named($options['brand'])->id : null,
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                    'description' => $description,
                    'price' => $price,
                    'compare_price' => $compare,
                    'stock' => $matrix === null ? $stock : 0,
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

                $product->setRelation('category', $this->categories[$category]);

                if (! empty($options['attrs'])) {
                    $attributes->sync($product, $options['attrs']);
                }

                if ($matrix !== null) {
                    $this->makeVariants($product, $variants, $matrix, (float) $price, $options['delta'] ?? [], $options['oos'] ?? [], $name);
                }

                // Two or three photos on every third listing so the gallery has something to do.
                $photos = $options['photos'] ?? ($i % 3 === 0 ? 3 : ($i % 3 === 1 ? 1 : 2));
                for ($n = 0; $n < $photos; $n++) {
                    $path = "product-images/{$product->id}/".Str::random(40).'.jpg';
                    Storage::disk('public')->put($path, $this->renderProduct($shape, $hue, $n, crc32($name)));
                    ProductImage::create(['product_id' => $product->id, 'path' => $path, 'sort_order' => $n]);
                }

                $created = now()->subDays(random_int(25, 85))->subMinutes(random_int(0, 1400));
                $product->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();

                // A listing that had an earlier price carries its history (a genuine drop, or a rise): the "was" price
                // shows only when the rules of ReferencePrice are met, never because the seller typed a compare-at price.
                if ($matrix === null) {
                    $history = $options['history'] ?? ($compare !== null && $flag === null ? [[75, $compare], [random_int(5, 14), $price]] : null);

                    if ($history !== null) {
                        $this->writePriceHistory($product, $history);
                    }
                }

                $this->products[$name] = $product->refresh();
            }
        }
    }

    /**
     * One variant per combination of the option values; stock is spread over 2 to 14 units by a fixed rule, a few
     * combinations are sold out on purpose, and some values cost more.
     *
     * @param  array<string, array<int, string>>  $matrix
     * @param  array<string, int|float>  $delta
     * @param  list<array<string, string>>  $outOfStock
     */
    private function makeVariants(Product $product, ProductVariants $variants, array $matrix, float $base, array $delta, array $outOfStock, string $seed): void
    {
        $variants->defineOptions($product, $matrix);

        $combos = [[]];

        foreach ($matrix as $option => $values) {
            $next = [];

            foreach ($combos as $combo) {
                foreach ($values as $value) {
                    $next[] = $combo + [$option => $value];
                }
            }

            $combos = $next;
        }

        foreach ($combos as $n => $choices) {
            $price = $base + array_sum(array_map(fn ($v) => $delta[$v] ?? 0, $choices));
            $stock = 2 + (crc32($seed.$n) % 13);

            foreach ($outOfStock as $out) {
                if (array_intersect_assoc($out, $choices) === $out) {
                    $stock = 0;
                }
            }

            $variants->saveVariant($product, $choices, ['price' => $price, 'stock' => $stock]);
        }
    }

    /**
     * @param  list<array{0: int, 1: int|float}>  $history  [days ago, price] oldest first; the last price is today's
     */
    private function writePriceHistory(Product $product, array $history): void
    {
        $variant = ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->firstOrFail();

        DB::table('product_price_history')->where('variant_id', $variant->id)->delete();

        $old = null;

        foreach ($history as [$daysAgo, $price]) {
            DB::table('product_price_history')->insert([
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'old_price' => $old,
                'new_price' => $price,
                'actor_id' => $product->store->owner_id,
                'changed_at' => $this->base->copy()->subDays((int) $daysAgo)->setTime(9, 0),
            ]);

            $old = $price;
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
        // 1. Paid, shipped with tracking, delivered. Karoo Home & Hearth, no promo.
        $o = $this->buy('thandi', [['Cast Iron Potjie Pot 3L', 1], ['Beeswax Taper Candles, 6 Pack', 2]], 52);
        $this->pay($o, 52);
        $this->shipWithTracking($o, 'lerato', 50, 'courier_guy', 'TCG48821907');
        $this->advance($o, null, OrderStatus::Delivered, 48);

        // 2. Paid and delivered, one store, percentage promo.
        $o = $this->buy('pieter', [['Alpine 2-Person Dome Tent', 1], ['LED Headlamp 400 Lumen', 1], ['Insulated Steel Bottle 750ml', 2]], 47, 'VELD15');
        $this->pay($o, 47);
        $this->shipWithTracking($o, 'johan', 45, 'aramex', '20418833761');
        $this->advance($o, null, OrderStatus::Delivered, 42);

        // 3. Paid and delivered with a percentage promo.
        $o = $this->buy('ayesha', [['Marula Facial Oil 30ml', 1], ['Rooibos Calming Face Mask', 1], ['Rooibos Tea, 80 Bags', 2]], 40, 'WELCOME10');
        $this->pay($o, 40);
        $this->shipWithTracking($o, 'zanele', 39, 'pargo', 'PRG7731204');
        $this->advance($o, null, OrderStatus::Delivered, 36);

        // 4. Paid and delivered, electronics.
        $o = $this->buy('naledi', [['Wireless Over-Ear Headphones', 1], ['65W USB-C Fast Charger', 1], ['A5 Dotted Notebook', 2]], 33);
        $this->pay($o, 33);
        $this->shipWithTracking($o, 'kabelo', 32, 'courier_guy', 'TCG49120331');
        $this->advance($o, null, OrderStatus::Delivered, 29);

        // 5. The multi-seller order: four stores, four different line statuses.
        $o = $this->buy('thandi', [
            ['Enamel Camping Mug, Set of 4', 1],
            ['20L Daypack', 1],
            ['Shea Butter Body Cream 250ml', 2],
            ['Braided USB-C Cable 2m', 1],
        ], 28);
        $this->pay($o, 28);
        $this->shipWithTracking($o, 'lerato', 27, 'courier_guy', 'TCG48990412');
        $this->advance($o, 'lerato', OrderStatus::Delivered, 24);
        $this->shipWithTracking($o, 'johan', 26, 'aramex', '20418899120', [[5, 'Collected from the seller', 'Underberg'], [30, 'Arrived at the Aramex hub', 'Pietermaritzburg']]);
        $this->advance($o, 'zanele', OrderStatus::Processing, 26);

        // 6. Shipped, with the seller's own tracking events.
        $o = $this->buy('sipho', [['Sleeping Bag, Comfort -5C', 1], ['Inflatable Sleeping Mat', 1]], 21);
        $this->pay($o, 21);
        $this->shipWithTracking($o, 'johan', 19, 'aramex', '20418744052', [[3, 'Collected from the seller', 'Underberg'], [20, 'Arrived at the Aramex hub', 'Pietermaritzburg'], [42, 'Departed for Pretoria', null], [70, 'Out for delivery', 'Pretoria']]);

        // 7. Paid, being packed, fixed-amount promo.
        $o = $this->buy('pieter', [['Cast Iron Skillet 26cm', 1], ['Olive Wood Serving Board', 1]], 17, 'BRAAI50');
        $this->pay($o, 17);
        $this->advance($o, null, OrderStatus::Processing, 16);

        // 8. Partially cancelled: one earbuds line cancelled after payment, partial refund.
        $o = $this->buy('naledi', [['Portable Bluetooth Speaker', 1], ['True Wireless Earbuds', 1]], 14);
        $this->pay($o, 14);
        $this->cancelLine($o, 'True Wireless Earbuds', 13);

        // 9. Fully cancelled and refunded.
        $o = $this->buy('ayesha', [['Gas Camping Stove', 1], ['Camp Cookset, 8 Piece', 1]], 12);
        $this->pay($o, 12);
        foreach ($o->items()->get() as $line) {
            $this->cancelLine($o, $line->product_name, 11);
        }

        // 10. Awaiting payment (the buyer left Paystack without paying).
        $o = $this->buy('sipho', [['Cotton Throw Blanket, Sand', 1], ['Woven Seagrass Basket', 1]], 1);
        $this->openAttempt($o, PaymentAttemptStatus::Pending, PaymentStatus::Pending, 1);

        // 11. Payment failed (declined card).
        $o = $this->buy('thandi', [['Mechanical Keyboard 75%', 1], ['Monitor Arm, Single', 1]], 1);
        $this->openAttempt($o, PaymentAttemptStatus::Failed, PaymentStatus::Failed, 1);

        // 12. Unpaid order the buyer gave up on (cancelled before payment).
        $o = $this->buy('naledi', [['Baobab Hand Cream 75ml', 2], ['Lip Balm, Set of 3', 1]], 6);
        $this->openAttempt($o, PaymentAttemptStatus::Pending, PaymentStatus::Pending, 6);
        $this->at(6, function () use ($o) {
            app(OrderLifecycle::class)->cancelUnpaidOrder($o->fresh());
            $o->refresh()->user->notify(new UnpaidOrderCancelledNotification($o));
        }, 12);

        // 13. Just placed and paid, two stores, nothing packed yet.
        $o = $this->buy('ayesha', [['Fynbos Soy Candle', 1], ['Charcoal Soap Bar', 3]], 0);
        $this->pay($o, 0);

        // 14. Placed before card payments existed: pending and unpaid, never gated.
        $this->payable(function () {
            $this->buy('pieter', [['Microfibre Travel Towel', 1]], 3);
        }, false);

        // --- Bought together: a laptop and a mouse in three different orders (the recommender needs three).
        $o = $this->buy('pieter', [['Lenovo IdeaPad Slim 3, 15.6 inch', 1], ['Wireless Mouse', 1]], 40, null, ['kabelo' => 'Courier (next working day)'], 'Home');
        $this->pay($o, 40);
        $this->shipWithTracking($o, 'kabelo', 39, 'courier_guy', 'TCG49011776');
        $this->advance($o, null, OrderStatus::Delivered, 36);

        $o = $this->buy('naledi', [['Lenovo IdeaPad Slim 3, 15.6 inch', 1], ['Wireless Mouse', 1], ['Aluminium Laptop Stand', 1]], 22);
        $this->pay($o, 22);
        $this->shipWithTracking($o, 'kabelo', 21, 'courier_guy', 'TCG49102988');
        $this->advance($o, null, OrderStatus::Delivered, 18);

        $o = $this->buy('sipho', [['Lenovo IdeaPad Slim 3, 15.6 inch', 1], ['Wireless Mouse', 1]], 15);
        $this->pay($o, 15);
        $this->shipWithTracking($o, 'kabelo', 14, 'courier_guy', 'TCG49150220');
        $this->advance($o, null, OrderStatus::Delivered, 11);

        // --- Bought together: a tent and a sleeping bag in three orders.
        $o = $this->buy('thandi', [['Alpine 2-Person Dome Tent', 1], ['Sleeping Bag, Comfort -5C', 1]], 33);
        $this->pay($o, 33);
        $this->shipWithTracking($o, 'johan', 32, 'aramex', '20418611870');
        $this->advance($o, null, OrderStatus::Delivered, 28);

        $o = $this->buy('naledi', [['Alpine 2-Person Dome Tent', 1], ['Sleeping Bag, Comfort -5C', 1], ['Inflatable Sleeping Mat', 1]], 26);
        $this->pay($o, 26);
        $this->shipWithTracking($o, 'johan', 25, 'aramex', '20418655014');
        $this->advance($o, null, OrderStatus::Delivered, 21);

        // --- A television and a phone with variants, a clothing order with sizes and colours.
        $o = $this->buy('thandi', [['Samsung 55 Inch Crystal UHD Smart TV', 1]], 20, 'TECH100');
        $this->pay($o, 20);
        $this->shipWithTracking($o, 'kabelo', 19, 'dsv', 'DSV-88120344', [[4, 'Collected from the seller', 'Braamfontein'], [26, 'At the Johannesburg depot', null], [52, 'Out for delivery', 'Parkhurst']]);
        $this->advance($o, null, OrderStatus::Delivered, 17);

        $o = $this->buy('sipho', [['Samsung Galaxy A15 Smartphone', 1, ['Storage' => '128GB', 'Colour' => 'Black']]], 13);
        $this->pay($o, 13);
        $this->shipWithTracking($o, 'kabelo', 12, 'courier_guy', 'TCG49188105');
        $this->advance($o, null, OrderStatus::Delivered, 9);

        $o = $this->buy('naledi', [['Samsung Galaxy S24 5G Smartphone', 1, ['Storage' => '256GB', 'Colour' => 'Onyx']]], 5, null, [], 'Home');
        $this->pay($o, 5);
        $this->shipWithTracking($o, 'kabelo', 4, 'courier_guy', 'TCG49201377', [[3, 'Collected from the seller', 'Braamfontein'], [28, 'Departed Johannesburg for Durban', null]]);

        $o = $this->buy('ayesha', [['Trail Leggings, High Waist', 1, ['Size' => 'S', 'Colour' => 'Black']], ['Fleece Jacket, Full Zip', 1, ['Size' => 'M', 'Colour' => 'Pink']]], 16);
        $this->pay($o, 16);
        $this->shipWithTracking($o, 'johan', 15, 'aramex', '20418688402');
        $this->advance($o, null, OrderStatus::Delivered, 12);

        // --- Collection: one collected, one ready and waiting.
        $o = $this->buy('sipho', [['Monitor Arm, Single', 1]], 8, null, ['kabelo' => 'Collect in Braamfontein']);
        $this->pay($o, 8);
        $this->advance($o, null, OrderStatus::Processing, 8, 14);
        $this->advance($o, null, OrderStatus::ReadyForCollection, 7);
        $this->advance($o, null, OrderStatus::Collected, 6);

        $o = $this->buy('thandi', [['Dell 24 Inch Full HD Monitor', 1]], 2, null, ['kabelo' => 'Collect in Braamfontein']);
        $this->pay($o, 2);
        $this->advance($o, null, OrderStatus::ReadyForCollection, 1);

        // --- Returns, one in every state. Each line was delivered inside the 7 day window.
        // requested: the seller has 10 days to answer.
        $o = $this->buy('thandi', [['Smart Plug, 2 Pack', 1], ['Wireless Mouse', 1]], 9);
        $this->pay($o, 9);
        $this->shipWithTracking($o, 'kabelo', 8, 'courier_guy', 'TCG49170544');
        $this->advance($o, null, OrderStatus::Delivered, 3);
        $this->returnRequest($o, 'Smart Plug, 2 Pack', 'thandi', 'faulty', 'One of the two plugs never connects to the app.', 2);

        // approved: the seller agreed, the goods are on their way back.
        $o = $this->buy('pieter', [['Trekking Poles, Pair', 1]], 8);
        $this->pay($o, 8);
        $this->shipWithTracking($o, 'johan', 7, 'aramex', '20418701148');
        $this->advance($o, null, OrderStatus::Delivered, 4);
        $return = $this->returnRequest($o, 'Trekking Poles, Pair', 'pieter', 'not_as_described', 'The listing says carbide tips; mine have rubber tips only.', 3);
        $this->at(2, fn () => app(ReturnService::class)->approve($return->refresh(), $this->users['johan'], 'Sorry about that. Please send them back and we will refund you.'), 12);

        // declined with a stated reason.
        $o = $this->buy('naledi', [['Exfoliating Body Scrub 300g', 1], ['Lip Balm, Set of 3', 1]], 8);
        $this->pay($o, 8);
        $this->shipWithTracking($o, 'zanele', 7, 'pargo', 'PRG7740018');
        $this->advance($o, null, OrderStatus::Delivered, 4);
        $return = $this->returnRequest($o, 'Exfoliating Body Scrub 300g', 'naledi', 'changed_mind', 'I prefer a finer scrub.', 3);
        $this->at(2, fn () => app(ReturnService::class)->decline($return->refresh(), $this->users['zanele'], 'For hygiene reasons we cannot take back an opened skincare jar unless it is faulty. The jar was opened according to your photo.'), 12);

        // received and refunded: the money went back, the line is Refunded, the ledger reversed.
        $o = $this->buy('ayesha', [['Hand-Glazed Stoneware Bowl Set', 1]], 9);
        $this->pay($o, 9);
        $this->shipWithTracking($o, 'lerato', 8, 'courier_guy', 'TCG48870031');
        $this->advance($o, null, OrderStatus::Delivered, 5);
        $return = $this->returnRequest($o, 'Hand-Glazed Stoneware Bowl Set', 'ayesha', 'faulty', 'One bowl arrived cracked along the rim.', 4);
        $this->at(3, fn () => app(ReturnService::class)->approve($return->refresh(), $this->users['lerato'], 'We are sorry. Please post it back.'), 12);
        $this->at(1, fn () => app(ReturnService::class)->markReceived($return->refresh(), $this->users['lerato'], false), 12);

        // declined, then escalated by the buyer: waiting in the operator's queue.
        $o = $this->buy('sipho', [['1080p Webcam', 1]], 9);
        $this->pay($o, 9);
        $this->shipWithTracking($o, 'kabelo', 8, 'courier_guy', 'TCG49170921');
        $this->advance($o, null, OrderStatus::Delivered, 5);
        $return = $this->returnRequest($o, '1080p Webcam', 'sipho', 'not_as_described', 'The picture is much darker than the listing photos suggest.', 4);
        $this->at(3, fn () => app(ReturnService::class)->decline($return->refresh(), $this->users['kabelo'], 'The webcam passed our test; low light performance is as described. Please try the brightness setting in your meeting app.'), 12);
        $this->at(2, fn () => app(ReturnService::class)->escalate($return->refresh(), $this->users['sipho'], 'I tried that and it is still too dark to use.'), 12);

        // declined, escalated, and the operator upheld the decline: closed.
        $o = $this->buy('naledi', [['Rose Geranium Face Mist 100ml', 1]], 9);
        $this->pay($o, 9);
        $this->shipWithTracking($o, 'zanele', 8, 'pargo', 'PRG7740102');
        $this->advance($o, null, OrderStatus::Delivered, 6);
        $return = $this->returnRequest($o, 'Rose Geranium Face Mist 100ml', 'naledi', 'other', 'The scent is not what I expected from the description.', 5);
        $this->at(4, fn () => app(ReturnService::class)->decline($return->refresh(), $this->users['zanele'], 'Scent is a matter of taste and the description names rose geranium. Opened skincare cannot be returned.'), 12);
        $this->at(3, fn () => app(ReturnService::class)->escalate($return->refresh(), $this->users['naledi'], 'The description did not say it is strongly perfumed.'), 12);
        $this->at(1, fn () => app(ReturnService::class)->operatorResolve($return->refresh(), $this->users['operator'], 'uphold_decline', 'The listing names the scent; opened skincare is not returnable for change of mind. Decline stands.'), 14);
    }

    /**
     * Places an order for $buyer from the lines (product name, quantity, optional variant choices) at $daysAgo,
     * the way the checkout page does: a cart through the cart service, then CheckoutService::place with a saved address
     * and the chosen delivery methods (the cheapest when none is named).
     *
     * @param  array<int, array{0: string, 1: int, 2?: array<string, string>}>  $lines
     * @param  array<string, string>  $methods  store key => delivery method name
     */
    private function buy(string $buyer, array $lines, int $daysAgo, ?string $promo = null, array $methods = [], ?string $addressLabel = null): Order
    {
        return $this->at($daysAgo, function () use ($buyer, $lines, $promo, $methods, $addressLabel) {
            $user = $this->users[$buyer];

            foreach ($lines as $line) {
                app(CartManager::class)->add($user, $this->variant($line[0], $line[2] ?? null), $line[1]);
            }

            $address = $addressLabel !== null
                ? Address::query()->where('user_id', $user->id)->where('label', $addressLabel)->firstOrFail()
                : $this->addresses[$buyer];

            // Left alone, checkout picks the cheapest method, which is "collect it yourself" at every store here. A shopper
            // who wants delivery picks the store's first delivery method, so that is the default; collection is named.
            foreach ($lines as $line) {
                $storeKey = array_search($this->products[$line[0]]->store_id, array_map(fn (Store $store) => $store->id, $this->stores), true);
                $methods[$storeKey] ??= collect($this->methods[$storeKey])->first(fn (ShippingMethod $m) => $m->type->value !== 'collection')->name;
            }

            $chosen = [];

            foreach ($methods as $storeKey => $methodName) {
                $chosen[$this->stores[$storeKey]->id] = $this->methods[$storeKey][$methodName]->id;
            }

            return app(CheckoutService::class)->place($user, array_filter([
                'address_id' => $address->id,
                'promo_code' => $promo,
                'shipping_methods' => $chosen,
            ]), (string) Str::uuid(), '127.0.0.1')->refresh();
        }, 9);
    }

    /**
     * @param  array<string, string>|null  $choices
     */
    private function variant(string $productName, ?array $choices = null): ProductVariant
    {
        $variants = ProductVariant::query()->where('product_id', $this->products[$productName]->id)->where('is_active', true)->orderBy('id')->get();

        if ($choices === null) {
            return $variants->firstOrFail();
        }

        return $variants->first(fn (ProductVariant $v) => ($v->options ?? []) == $choices) ?? throw new RuntimeException("No variant of {$productName} for ".json_encode($choices));
    }

    /**
     * An order paid through the real PaymentService: a pending attempt, then the same confirmation call the Paystack
     * webhook makes.
     */
    private function pay(Order $order, int $daysAgo): void
    {
        $this->at($daysAgo, function () use ($order) {
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
        }, 10);
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

    private function openAttempt(Order $order, PaymentAttemptStatus $attempt, PaymentStatus $orderStatus, int $daysAgo): void
    {
        $this->at($daysAgo, function () use ($order, $attempt, $orderStatus) {
            $this->attempt($order, $attempt);
            $order->forceFill(['payment_status' => $orderStatus])->saveQuietly();
        }, 10);
    }

    /**
     * The seller marks the parcel shipped through ShipmentService, with a carrier and tracking number from the allowlist
     * and the text events the seller typed ([minutes after shipping, text, location]).
     *
     * @param  list<array{0: int, 1: string, 2: ?string}>  $events
     */
    private function shipWithTracking(Order $order, string $storeKey, int $daysAgo, string $carrier, string $number, array $events = []): Shipment
    {
        return $this->at($daysAgo, function () use ($order, $storeKey, $carrier, $number, $events) {
            $group = $order->groups()->where('store_id', $this->stores[$storeKey]->id)->firstOrFail();
            $seller = $this->users[$storeKey];
            $service = app(ShipmentService::class);

            $shipment = $service->ship($group, $seller, [
                'carrier_key' => $carrier,
                'tracking_number' => $number,
                'estimated_from' => now()->addWeekdays(2)->toDateString(),
                'estimated_to' => now()->addWeekdays(4)->toDateString(),
            ]);

            foreach ($events as [$minutes, $text, $location]) {
                $service->addEvent($shipment, $seller, $text, $location, now()->addMinutes($minutes * 10));
            }

            return $shipment;
        }, 11);
    }

    /**
     * Moves the lines (every line of the order, or one store's) to $to as the seller, one legal step at a time.
     */
    private function advance(Order $order, ?string $storeKey, OrderStatus $to, int $daysAgo, int $hour = 14): void
    {
        $this->at($daysAgo, function () use ($order, $storeKey, $to) {
            $lifecycle = app(OrderLifecycle::class);

            $lines = $order->items()->when($storeKey !== null, fn ($q) => $q->where('store_id', $this->stores[$storeKey]->id))->get();

            foreach ($lines as $line) {
                $seller = User::query()->find($line->seller_id);

                // A pending line is shipped on the way to delivered if nobody did it (a shipment normally has).
                if ($to === OrderStatus::Delivered && $line->refresh()->status === OrderStatus::Pending) {
                    $lifecycle->advanceItem($line, OrderStatus::Shipped, $seller);
                }

                $lifecycle->advanceItem($line->refresh(), $to, $seller);
            }
        }, $hour);
    }

    private function cancelLine(Order $order, string $productName, int $daysAgo): void
    {
        $this->at($daysAgo, function () use ($order, $productName) {
            $line = $order->items()->where('product_name', $productName)->firstOrFail();
            app(OrderLifecycle::class)->cancelItem($line, null, 'Out of stock at the warehouse', 'seller');
        }, 12);
    }

    private function returnRequest(Order $order, string $productName, string $buyerKey, string $reason, string $note, int $daysAgo): ReturnRequest
    {
        return $this->at($daysAgo, function () use ($order, $productName, $buyerKey, $reason, $note) {
            $line = $order->items()->where('product_name', $productName)->firstOrFail();

            return app(ReturnService::class)->request($line->refresh(), $this->users[$buyerKey], $reason, $note);
        }, 9);
    }

    // ---------------------------------------------------------------- reviews

    private function makeReviews(): void
    {
        $service = app(ReviewService::class);

        // Verified: each of these buyers has a delivered line for the product, so ReviewService marks them verified.
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
            ['pieter', 'Lenovo IdeaPad Slim 3, 15.6 inch', 4, 'Quick to start and the screen is good enough for a day of spreadsheets. The speakers are thin.', 31],
            ['naledi', 'Lenovo IdeaPad Slim 3, 15.6 inch', 4, 'Does everything my studies need. Battery lasts a full lecture day if I keep brightness down.', 14],
            ['sipho', 'Lenovo IdeaPad Slim 3, 15.6 inch', 5, 'Bought it with the mouse and set up took ten minutes. Very happy for the price.', 7],
            ['pieter', 'Wireless Mouse', 5, 'Silent clicks, which my open-plan office appreciates. Connected to two laptops without fuss.', 30],
            ['thandi', 'Samsung 55 Inch Crystal UHD Smart TV', 5, 'The picture is sharp and the apps are all there. Setup took a few minutes. Delivery was on the day the seller said.', 14],
            ['sipho', 'Samsung Galaxy A15 Smartphone', 4, 'Good screen and the battery lasts two days. The camera struggles in low light.', 6],
            ['ayesha', 'Trail Leggings, High Waist', 4, 'Fit is true to size and the phone pocket actually holds a phone. Slightly sheer in the lightest colour.', 9],
            ['thandi', 'Alpine 2-Person Dome Tent', 4, 'Easy to pitch. Two adults fit but there is no room for bags inside, so bring a footprint.', 24],
            ['naledi', 'Alpine 2-Person Dome Tent', 5, 'Used it in the Drakensberg. Dry through a night of rain.', 17],
        ];

        foreach ($verified as [$buyer, $name, $rating, $body, $daysAgo]) {
            $product = $this->products[$name];
            $review = $this->at($daysAgo, fn () => $service->submit($this->users[$buyer], $product, $rating, $body), 15);
            $product->store->owner->notify(new NewReviewNotification($review));
        }

        // Unverified: written by people with no delivered order for the product. The application refuses these today,
        // so they are inserted directly, the way a legacy row or a data import would arrive. They show how the
        // storefront treats them.
        $unverified = [
            ['sipho', 'Alpine 2-Person Dome Tent', 4, 'Borrowed a friend\'s and liked it enough to put one on my list.', 16],
            ['ayesha', 'Wireless Over-Ear Headphones', 5, 'Great sound. My brother has these and I am jealous.', 12],
            ['sipho', 'Cast Iron Potjie Pot 3L', 2, 'Seems overpriced for what it is.', 8],
        ];

        foreach ($unverified as [$buyer, $name, $rating, $body, $daysAgo]) {
            Review::create([
                'product_id' => $this->products[$name]->id,
                'user_id' => $this->users[$buyer]->id,
                'rating' => $rating,
                'body' => $body,
                'created_at' => now()->subDays($daysAgo),
                'updated_at' => now()->subDays($daysAgo),
            ]);
        }

        foreach (array_unique(array_column($unverified, 1)) as $name) {
            $service->refreshAggregate($this->products[$name]);
        }

        // A reply from the store, helpful votes, and a report that lands in the operator's queue.
        $ids = fn (string $buyer, string $name) => Review::query()->where('user_id', $this->users[$buyer]->id)->where('product_id', $this->products[$name]->id)->firstOrFail();

        $this->at(12, fn () => app(ReviewResponses::class)->respond($this->users['kabelo'], $ids('pieter', 'Lenovo IdeaPad Slim 3, 15.6 inch'), 'Thanks Pieter. The speakers are the usual trade-off at this price; a small Bluetooth speaker pairs in seconds.'), 13);
        $this->at(26, fn () => app(ReviewResponses::class)->respond($this->users['johan'], $ids('pieter', 'Alpine 2-Person Dome Tent'), 'Glad it held in the south-easter. Thank you for the review.'), 13);

        $votes = app(ReviewVotes::class);

        foreach (['sipho', 'naledi', 'ayesha', 'thandi'] as $voter) {
            $votes->toggle($this->users[$voter], $ids('pieter', 'Alpine 2-Person Dome Tent'));
        }

        foreach (['thandi', 'ayesha'] as $voter) {
            $votes->toggle($this->users[$voter], $ids('naledi', 'Lenovo IdeaPad Slim 3, 15.6 inch'));
        }

        app(ContentReports::class)->report($this->users['pieter'], 'review', $ids('sipho', 'Cast Iron Potjie Pot 3L')->id, 'off_topic', 'This reviewer has not bought this product and the review is about price only.');
    }

    // ------------------------------------------------------------------ guests

    /**
     * A shopper who never signed in: a paid order that the seller has shipped, reached only through its magic link.
     * Returns the link.
     */
    private function makeGuestOrders(): ?string
    {
        $link = null;

        $this->payable(function () use (&$link) {
            $order = $this->at(4, function () {
                $lines = [
                    ['variant_id' => $this->variant('Braided USB-C Cable 2m')->id, 'quantity' => 2],
                    ['variant_id' => $this->variant('Rooibos Tea, 80 Bags')->id, 'quantity' => 1],
                ];

                return app(CheckoutService::class)->placeAsGuest(self::GUEST_EMAIL, $lines, [
                    'shipping_methods' => [
                        $this->stores['kabelo']->id => $this->methods['kabelo']['Courier (next working day)']->id,
                        $this->stores['zanele']->id => $this->methods['zanele']['Pargo pick-up point']->id,
                    ],
                    'address' => [
                        'recipient_name' => 'Guest Shopper', 'phone' => '+27 82 555 0100', 'line1' => '88 Visagie Street', 'suburb' => 'Pretoria Central',
                        'city' => 'Pretoria', 'province' => 'Gauteng', 'postal_code' => '0002', 'country' => 'ZA',
                    ],
                ], (string) Str::uuid(), '127.0.0.1');
            }, 9);

            $this->pay($order->refresh(), 4);
            $this->shipWithTracking($order->refresh(), 'kabelo', 3, 'courier_guy', 'TCG49211530', [[4, 'Collected from the seller', 'Braamfontein'], [30, 'At the Pretoria depot', null]]);
            $this->advance($order->refresh(), 'zanele', OrderStatus::Processing, 3);

            $link = app(GuestOrderAccess::class)->url($order->refresh());
        });

        return $link;
    }

    // ------------------------------------------------------------------ finish

    /**
     * What the scheduler would have done by now: release the ledger entries whose waiting period has passed, rebuild the
     * ratings, the search index and the recommendation links.
     */
    private function finish(): void
    {
        app(LedgerService::class)->releaseDue(Carbon::now());
        app(StoreRatings::class)->refreshAll();

        Artisan::call('emall:search-reindex');
        Artisan::call('emall:recommendations-rebuild');

        Vocabulary::forget();
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
