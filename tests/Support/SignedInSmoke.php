<?php

namespace Tests\Support;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Collection;
use App\Models\ContentReport;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Discovery\Wishlists;
use App\Services\Guests\GuestOrderAccess;
use App\Services\OrderLifecycle;
use App\Services\Returns\ReturnPhotos;
use App\Services\Returns\ReturnService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The signed-in smoke check (R-43): build a small, complete marketplace, then open every page as the person who is
 * meant to see it and compare the answer with the one expected. Used two ways:
 *
 *   - Tests\Feature\Audit\SignedInSmokeTest runs it in the normal suite (routes as the suite has them);
 *   - tests/Support/signed-in-smoke.sh runs it with `route:cache`, `config:cache` and `event:cache` all active, which is
 *     how production runs (tests/Support/signed-in-smoke.php drives it).
 *
 * pages() lists what is opened; uncoveredRoutes() lists authenticated GET routes that are neither opened nor deliberately
 * skipped, so a new page cannot be forgotten.
 */
final class SignedInSmoke
{
    /** Authenticated GET routes that are not opened, and why. */
    public const SKIPPED = [
        'api/user' => 'token API (Sanctum bearer tokens), not a page; a cookie session answers 401 by design',
        'orders/{order}/payment/callback' => 'asks Paystack to verify a payment; covered by PaystackCallbackTest with a faked gateway',
        'team-invitations/{invitation}' => 'needs a signed invitation link; covered by InviteTeamMemberTest',
        'user/two-factor-qr-code' => 'needs a recent password confirmation and 2FA set up; covered by TwoFactorAuthenticationSettingsTest',
        'user/two-factor-recovery-codes' => 'needs a recent password confirmation and 2FA set up',
        'user/two-factor-secret-key' => 'needs a recent password confirmation and 2FA set up',
        'user/confirm-password' => "Fortify's own page; it redirects when a password confirmation is still fresh, so its status depends on the session",
        'user/confirmed-password-status' => 'JSON status, not a page',
        'operator/reports/{type}/{id}' => 'opened as /operator/reports/product/{id}: the {type} segment is a word, which the pattern matcher does not collapse',
        'account/returns/{return}' => 'needs a delivered line inside its return window; covered by ReturnFlowTest',
        'sell/analytics/export/{what}' => 'CSV downloads; both kinds are opened above under their literal addresses (export/orders, export/products)',
    ];

    /** @var array<string, mixed> */
    private array $world = [];

    /** @var array<string, string> */
    private array $storefront = [];

    /** @var array<int, string> files this run wrote to the private disk, removed again by forget() */
    private array $files = [];

    /**
     * Create the data. Returns the people, keyed 'seller', 'buyer', 'newcomer', 'operator'.
     *
     * @return array<string, User>
     */
    public function seed(): array
    {
        $seller = $this->user('Smoke Seller', 'seller');
        $buyer = $this->user('Smoke Buyer', 'buyer');
        $newcomer = $this->user('Smoke Newcomer', 'newcomer');
        $operator = $this->user('Smoke Operator', 'operator', operator: true);

        $store = Store::create(['owner_id' => $seller->id, 'name' => 'Smoke Store', 'slug' => 'smoke-store-'.uniqid(), 'description' => 'Smoke test store.', 'is_active' => true]);
        $category = StoreCategory::create(['name' => 'Smoke Kitchen', 'slug' => 'smoke-kitchen']);
        $live = $this->product($store, 'Smoke Kettle', ['price' => '120.00', 'stock' => 20, 'category_id' => $category->id, 'brand_id' => Brand::named('Smoke Brand')->id]);
        $pending = $this->product($store, 'Smoke Pending Lamp', ['status' => 'pending_approval']);

        $order = $this->placeOrder($buyer, $live);
        $line = $order->items()->first();

        // Ship and deliver it, then let the buyer ask to return it, so the seller desk has a parcel with a shipment and a return to open.
        $lifecycle = app(OrderLifecycle::class);
        $lifecycle->advanceItem($line, OrderStatus::Shipped, $seller, null, 'seller');
        $lifecycle->advanceItem($line->fresh(), OrderStatus::Delivered, $seller, null, 'seller');
        $return = app(ReturnService::class)->request($line->fresh(), $buyer, 'faulty', 'Smoke return');
        $group = $line->fresh()->group;

        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $live->id, 'quantity' => 2]);

        // A guest who never signed in: reached only by the link in their e-mail (D-9). The path is what the browser opens.
        $guestOrder = app(CheckoutService::class)->placeAsGuest('smoke-guest@example.test', [['variant_id' => ProductVariant::query()->where('product_id', $live->id)->value('id'), 'quantity' => 1]], ['shipping_address' => '2 Smoke Street, Testville', 'shipping_name' => 'Smoke Guest'], (string) Str::uuid());
        $guestLink = (string) parse_url((string) app(GuestOrderAccess::class)->url($guestOrder), PHP_URL_PATH);

        // Something in every operator queue, so those pages draw rows and not only their empty states.
        $report = ContentReport::query()->create(['reportable_type' => 'product', 'reportable_id' => $live->id, 'reporter_id' => $newcomer->id, 'reason' => 'fake', 'details' => 'Looks like a copy']);
        $collection = Collection::query()->create(['title' => 'Smoke collection', 'slug' => 'smoke-collection', 'type' => 'manual', 'visibility' => 'draft']);
        $return = (new ReturnRequest)->forceFill(['order_item_id' => $line->id, 'order_id' => $order->id, 'requester_id' => $buyer->id, 'seller_id' => $seller->id, 'reason_category' => 'faulty', 'status' => 'escalated', 'refund_cents' => 12000, 'escalated_at' => now(), 'escalation_reason' => 'buyer_disputed_decline', 'respond_by' => now()->addDays(10)]);
        $return->save();

        // A photo the buyer attached (written where the buyer's upload writes it: private disk, returns/{order id}/), so both the buyer's and the seller's way of opening it are exercised.
        $photo = 'returns/'.$order->id.'/smoke-'.Str::random(8).'.jpg';
        Storage::disk(ReturnPhotos::DISK)->put($photo, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
        $this->files[] = $photo;
        $return->forceFill(['photo_paths' => [$photo]])->save();

        $address = Address::create(['user_id' => $buyer->id, 'recipient_name' => 'Smoke Buyer', 'line1' => '1 Smoke Street', 'city' => 'Testville', 'province' => 'Gauteng', 'postal_code' => '2000', 'country' => 'ZA', 'is_default_shipping' => true]);
        $item = $order->items()->first();

        $this->seedStorefront($buyer, $live);

        $this->world = compact('store', 'live', 'pending', 'order', 'line', 'group', 'return', 'seller', 'buyer', 'newcomer', 'operator', 'category', 'guestLink', 'report', 'collection', 'address', 'item');

        return compact('seller', 'buyer', 'newcomer', 'operator');
    }

    /** R-43 W4: one saved product on a shared wishlist, so the share page, the wishlist and the alerts page have something to open. */
    private function seedStorefront(User $buyer, Product $live): void
    {
        $lists = app(Wishlists::class);
        $lists->add($buyer, $live);
        $lists->enableSharing($lists->defaultFor($buyer));
        $this->storefront = ['wishToken' => (string) $lists->defaultFor($buyer)->share_token];
    }

    /** Removes what seed() wrote outside the database. */
    public function forget(): void
    {
        foreach ($this->files as $file) {
            Storage::disk(ReturnPhotos::DISK)->delete($file);
        }

        $this->files = [];
    }

    /**
     * @return array<int, array{who: string, uri: string, expect?: int}>
     */
    public function pages(): array
    {
        $w = $this->world;
        $live = $w['live']->id;
        $pending = $w['pending']->id;
        $order = $w['order']->id;
        $group = $w['group']->id;
        $return = $w['return']->id;

        return [
            // anyone
            ['who' => 'guest', 'uri' => '/'],
            ['who' => 'guest', 'uri' => '/products'],
            ['who' => 'guest', 'uri' => '/products?q=kettle'],
            ['who' => 'guest', 'uri' => '/products?category='.$w['category']->id],
            ['who' => 'guest', 'uri' => '/products?q=kettle&category='.$w['category']->id],
            ['who' => 'guest', 'uri' => '/search?q=kettle'],
            ['who' => 'guest', 'uri' => '/search?q=kettle&sort=price_asc&view=list'],
            ['who' => 'guest', 'uri' => '/search?q=zzzzqq'],
            ['who' => 'guest', 'uri' => '/categories'],
            ['who' => 'guest', 'uri' => '/categories/'.$w['category']->slug],
            ['who' => 'guest', 'uri' => '/categories/no-such-category', 'expect' => 404],
            ['who' => 'guest', 'uri' => '/collections'],
            ['who' => 'guest', 'uri' => '/collections/no-such-collection', 'expect' => 404],
            ['who' => 'guest', 'uri' => '/assistant'],
            ['who' => 'guest', 'uri' => '/assistant?text=a+kettle+under+R500'],
            ['who' => 'guest', 'uri' => '/recently-viewed'],
            ['who' => 'guest', 'uri' => '/account/saved-searches', 'expect' => 302],
            ['who' => 'guest', 'uri' => '/search/suggest?q=ke'],
            ['who' => 'guest', 'uri' => '/search/suggest?q=smoke%20kit'],
            ['who' => 'guest', 'uri' => $w['guestLink'], 'expect' => 302],
            ['who' => 'guest', 'uri' => '/cart'],
            ['who' => 'guest', 'uri' => '/checkout', 'expect' => 302],
            ['who' => 'guest', 'uri' => '/help'],
            ['who' => 'guest', 'uri' => '/help/delivery'],
            ['who' => 'guest', 'uri' => '/help/returns'],
            ['who' => 'guest', 'uri' => '/help/contact'],
            ['who' => 'guest', 'uri' => '/help/selling'],
            ['who' => 'guest', 'uri' => "/guest/orders/{$order}", 'expect' => 404],
            ['who' => 'guest', 'uri' => "/products/{$live}"],
            ['who' => 'guest', 'uri' => "/products/{$pending}", 'expect' => 404],
            ['who' => 'guest', 'uri' => '/login'],
            ['who' => 'guest', 'uri' => '/cookies'],
            ['who' => 'guest', 'uri' => '/up'],
            ['who' => 'guest', 'uri' => '/dashboard', 'expect' => 302],
            // buyer
            ['who' => 'buyer', 'uri' => '/dashboard'],
            ['who' => 'buyer', 'uri' => '/products'],
            ['who' => 'buyer', 'uri' => "/products/{$live}"],
            ['who' => 'buyer', 'uri' => '/cart'],
            ['who' => 'buyer', 'uri' => '/orders'],
            ['who' => 'buyer', 'uri' => "/orders/{$order}"],
            ['who' => 'buyer', 'uri' => "/orders/{$order}/receipt"],
            ['who' => 'buyer', 'uri' => "/orders/{$order}/items/{$w['item']->id}/return", 'expect' => 302],
            ['who' => 'buyer', 'uri' => '/checkout'],
            ['who' => 'buyer', 'uri' => '/account/returns'],
            ['who' => 'buyer', 'uri' => '/account/addresses'],
            ['who' => 'buyer', 'uri' => "/account/addresses/{$w['address']->id}/edit"],
            ['who' => 'buyer', 'uri' => '/account/notifications'],
            ['who' => 'buyer', 'uri' => '/account/privacy'],
            ['who' => 'buyer', 'uri' => '/account/privacy/export'],
            ['who' => 'buyer', 'uri' => '/user/profile'],
            ['who' => 'buyer', 'uri' => '/teams/create'],
            ['who' => 'buyer', 'uri' => '/teams/'.$w['buyer']->personalTeam()->id],
            ['who' => 'buyer', 'uri' => '/account'],
            ['who' => 'buyer', 'uri' => '/account/saved-searches'],
            ['who' => 'buyer', 'uri' => '/recently-viewed'],
            ['who' => 'buyer', 'uri' => '/search?q=kettle'],
            ['who' => 'buyer', 'uri' => '/assistant?text=a+kettle'],
            // someone who is not a seller yet
            ['who' => 'newcomer', 'uri' => '/sell'],
            ['who' => 'newcomer', 'uri' => '/sell/start'],
            ['who' => 'newcomer', 'uri' => '/sell/products', 'expect' => 302],
            ['who' => 'newcomer', 'uri' => '/sell/dashboard', 'expect' => 302],
            ['who' => 'guest', 'uri' => '/sell/start'],
            // seller
            ['who' => 'seller', 'uri' => '/dashboard'],
            ['who' => 'seller', 'uri' => '/sell/dashboard'],
            ['who' => 'seller', 'uri' => "/products/{$pending}"],
            ['who' => 'seller', 'uri' => '/sell/store/edit'],
            ['who' => 'seller', 'uri' => '/sell/products'],
            ['who' => 'seller', 'uri' => '/sell/products/create'],
            ['who' => 'seller', 'uri' => "/sell/products/{$live}/edit"],
            ['who' => 'seller', 'uri' => '/sell/orders'],
            ['who' => 'seller', 'uri' => "/orders/{$order}"],
            ['who' => 'seller', 'uri' => '/sell/promo-codes'],
            // the rest of the seller desk (W6)
            ['who' => 'seller', 'uri' => '/sell/store'],
            ['who' => 'seller', 'uri' => "/sell/products/{$live}/stock"],
            ['who' => 'seller', 'uri' => '/sell/shipping'],
            ['who' => 'seller', 'uri' => '/sell/shipping?province=Gauteng&basket=500'],
            ['who' => 'seller', 'uri' => '/sell/products?q=kettle&stock=low&status=approved'],
            ['who' => 'seller', 'uri' => '/sell/orders'],
            ['who' => 'seller', 'uri' => '/sell/orders?tab=delivered'],
            ['who' => 'seller', 'uri' => "/sell/orders/{$group}"],
            ['who' => 'seller', 'uri' => "/sell/orders/{$group}/slip"],
            ['who' => 'seller', 'uri' => '/sell/returns'],
            ['who' => 'seller', 'uri' => "/sell/returns/{$return}"],
            ['who' => 'seller', 'uri' => "/sell/returns/{$return}/photos/0"],
            ['who' => 'seller', 'uri' => "/sell/returns/{$return}/photos/1", 'expect' => 404],
            ['who' => 'buyer', 'uri' => "/return-photos/{$return}/0"],
            ['who' => 'newcomer', 'uri' => "/return-photos/{$return}/0", 'expect' => 404],
            ['who' => 'seller', 'uri' => '/sell/analytics'],
            ['who' => 'seller', 'uri' => '/sell/analytics?period=7'],
            ['who' => 'seller', 'uri' => '/sell/analytics/export/orders'],
            ['who' => 'seller', 'uri' => '/sell/analytics/export/products'],
            ['who' => 'seller', 'uri' => '/sell/ledger'],
            ['who' => 'seller', 'uri' => '/sell/ledger/export'],
            ['who' => 'seller', 'uri' => '/sell/reviews'],
            // another seller's parcel and return are not theirs to open
            ['who' => 'newcomer', 'uri' => "/sell/orders/{$group}", 'expect' => 302],
            // somebody else's order is not theirs to open
            ['who' => 'newcomer', 'uri' => "/orders/{$order}", 'expect' => 403],
            ['who' => 'newcomer', 'uri' => '/operator/products', 'expect' => 403],
            // operator
            ['who' => 'operator', 'uri' => '/operator/products'],
            ['who' => 'operator', 'uri' => '/operator/payments'],
            ['who' => 'operator', 'uri' => "/orders/{$order}"],
            ['who' => 'operator', 'uri' => '/operator'],
            ['who' => 'operator', 'uri' => "/operator/products/{$pending}"],
            ['who' => 'operator', 'uri' => '/operator/products?status=all'],
            ['who' => 'operator', 'uri' => '/operator/flags'],
            ['who' => 'operator', 'uri' => '/operator/stores?status=all'],
            ['who' => 'operator', 'uri' => '/operator/stores/'.$w['store']->id],
            ['who' => 'operator', 'uri' => '/operator/moderation'],
            ['who' => 'operator', 'uri' => '/operator/moderation?kind=questions'],
            ['who' => 'operator', 'uri' => '/operator/reports'],
            ['who' => 'operator', 'uri' => '/operator/reports?view=repeat'],
            ['who' => 'operator', 'uri' => '/operator/reports/product/'.$live],
            ['who' => 'operator', 'uri' => '/operator/returns'],
            ['who' => 'operator', 'uri' => '/operator/returns/'.$w['return']->id],
            ['who' => 'operator', 'uri' => '/operator/payment-events'],
            ['who' => 'operator', 'uri' => '/operator/ledger'],
            ['who' => 'operator', 'uri' => '/operator/metrics'],
            ['who' => 'operator', 'uri' => '/operator/metrics/export'],
            ['who' => 'operator', 'uri' => '/operator/merchandising'],
            ['who' => 'operator', 'uri' => '/operator/collections'],
            ['who' => 'operator', 'uri' => '/operator/collections/create'],
            ['who' => 'operator', 'uri' => '/operator/collections/find?q=smoke'],
            ['who' => 'operator', 'uri' => '/operator/collections/'.$w['collection']->id.'/edit'],
            ['who' => 'operator', 'uri' => '/operator/search/synonyms?test=kettle'],
            ['who' => 'operator', 'uri' => '/operator/search/queries'],
            ['who' => 'operator', 'uri' => '/operator/search/complements'],
            ['who' => 'operator', 'uri' => '/operator/catalogue/categories?category='.$w['category']->id],
            ['who' => 'operator', 'uri' => '/operator/catalogue/featured'],
            ['who' => 'operator', 'uri' => '/operator/people?type=user&q=smoke'],
            ['who' => 'operator', 'uri' => '/operator/people/users/'.$w['buyer']->id],
            ['who' => 'operator', 'uri' => '/operator/people/stores/'.$w['store']->id],
            ['who' => 'operator', 'uri' => "/operator/people/orders/{$order}"],
            ['who' => 'operator', 'uri' => '/operator/operators'],
            ['who' => 'operator', 'uri' => '/operator/health'],
            ['who' => 'operator', 'uri' => '/operator/audit'],
            ['who' => 'operator', 'uri' => '/operator/audit?tab=access'],
            // and nobody else gets in
            ['who' => 'newcomer', 'uri' => '/operator', 'expect' => 403],
            ['who' => 'seller', 'uri' => '/operator/metrics/export', 'expect' => 403],
            ['who' => 'buyer', 'uri' => '/operator/people', 'expect' => 403],
            // w4: store pages, the product page in its other shapes, wishlist and alerts
            ['who' => 'guest', 'uri' => '/stores'],
            ['who' => 'guest', 'uri' => '/stores?q=smoke&region=Gauteng&sort=rating'],
            ['who' => 'guest', 'uri' => '/stores/'.$w['store']->slug],
            ['who' => 'guest', 'uri' => '/stores/'.$w['store']->slug.'?q=kettle&sort=price_asc&in_stock=1'],
            ['who' => 'guest', 'uri' => '/stores/no-such-store', 'expect' => 404],
            ['who' => 'guest', 'uri' => "/products/{$live}?reviews_sort=newest&reviews_verified=1&reviews_photos=1"],
            ['who' => 'guest', 'uri' => "/products/{$live}?src=search&opt[Size]=M"],
            ['who' => 'guest', 'uri' => '/wishlist/shared/'.$this->storefront['wishToken']],
            ['who' => 'guest', 'uri' => '/wishlist/shared/'.str_repeat('z', 40), 'expect' => 404],
            ['who' => 'buyer', 'uri' => "/products/{$live}"],
            ['who' => 'buyer', 'uri' => '/stores/'.$w['store']->slug],
            ['who' => 'buyer', 'uri' => '/wishlist'],
            ['who' => 'buyer', 'uri' => '/account/alerts'],
            ['who' => 'seller', 'uri' => "/products/{$live}"],
            ['who' => 'seller', 'uri' => '/stores/'.$w['store']->slug],
        ];
    }

    /**
     * Authenticated GET routes that pages() neither opens nor SKIPPED explains.
     *
     * @return array<int, string>
     */
    public function uncoveredRoutes(): array
    {
        $opened = collect($this->pages())->map(fn ($p) => $this->pattern($p['uri']))->unique()->all();
        $uncovered = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $needsLogin = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_contains($m, 'Authenticate') || str_starts_with($m, 'auth')) && ! str_contains($m, 'RedirectIfAuthenticated'));

            if (! $needsLogin || array_key_exists($route->uri(), self::SKIPPED)) {
                continue;
            }

            if (! in_array($this->pattern('/'.$route->uri()), $opened, true)) {
                $uncovered[] = $route->uri();
            }
        }

        return array_values(array_unique($uncovered));
    }

    /** "/orders/12/pay" -> "orders/{}/pay" so URIs and route patterns compare. */
    private function pattern(string $uri): string
    {
        $path = trim((string) strtok($uri, '?'), '/');

        return (string) preg_replace(['/\{[^}]+\}/', '/(?<=\/|^)\d+(?=\/|$)/', '/(?<=\/|^)[0-9a-f]{8}-[0-9a-f-]{27}(?=\/|$)/'], '{}', $path);
    }

    private function user(string $name, string $key, bool $operator = false): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name, 'email' => "smoke-{$key}-".uniqid().'@example.test']);
        $user->forceFill(['email_verified_at' => now(), 'is_platform_operator' => $operator])->save();

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(Store $store, string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'store_id' => $store->id, 'name' => $name, 'slug' => 'smoke-'.uniqid(), 'description' => 'Smoke test product.',
            'price' => '50.00', 'stock' => 10, 'is_active' => true, 'status' => 'approved',
        ], $overrides));
    }

    private function placeOrder(User $buyer, Product $product): Order
    {
        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        return app(CheckoutService::class)->place($buyer, ['shipping_address' => '1 Smoke Street, Testville', 'shipping_name' => 'Smoke Buyer'], (string) Str::uuid());
    }
}
