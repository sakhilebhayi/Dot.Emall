<?php

namespace Tests\Support;

use App\Models\Brand;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Guests\GuestOrderAccess;
use Illuminate\Support\Facades\Route;
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
        'sell/analytics/export/{what}' => 'CSV downloads; both kinds are opened above under their literal addresses (export/orders, export/products)',
        'sell/returns/{return}/photos/{index}' => 'streams a photo the buyer attached; return photos have no upload path yet (D-25), covered by SellerReturnsTest',
    ];

    /** @var array<string, mixed> */
    private array $world = [];

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
        $lifecycle = app(\App\Services\OrderLifecycle::class);
        $lifecycle->advanceItem($line, \App\Enums\OrderStatus::Shipped, $seller, null, 'seller');
        $lifecycle->advanceItem($line->fresh(), \App\Enums\OrderStatus::Delivered, $seller, null, 'seller');
        $return = app(\App\Services\Returns\ReturnService::class)->request($line->fresh(), $buyer, 'faulty', 'Smoke return');
        $group = $line->fresh()->group;

        CartItem::withoutGlobalScopes()->create(['user_id' => $buyer->id, 'product_id' => $live->id, 'quantity' => 2]);

        // A guest who never signed in: reached only by the link in their e-mail (D-9). The path is what the browser opens.
        $guestOrder = app(CheckoutService::class)->placeAsGuest('smoke-guest@example.test', [['variant_id' => ProductVariant::query()->where('product_id', $live->id)->value('id'), 'quantity' => 1]], ['shipping_address' => '2 Smoke Street, Testville', 'shipping_name' => 'Smoke Guest'], (string) Str::uuid());
        $guestLink = (string) parse_url((string) app(GuestOrderAccess::class)->url($guestOrder), PHP_URL_PATH);

        $this->world = compact('store', 'live', 'pending', 'order', 'line', 'group', 'return', 'seller', 'buyer', 'newcomer', 'operator', 'category', 'guestLink');

        return compact('seller', 'buyer', 'newcomer', 'operator');
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
            ['who' => 'guest', 'uri' => '/search/suggest?q=ke'],
            ['who' => 'guest', 'uri' => '/search/suggest?q=smoke%20kit'],
            ['who' => 'guest', 'uri' => $w['guestLink']],
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
            ['who' => 'buyer', 'uri' => '/user/profile'],
            ['who' => 'buyer', 'uri' => '/teams/create'],
            ['who' => 'buyer', 'uri' => '/teams/'.$w['buyer']->personalTeam()->id],
            ['who' => 'buyer', 'uri' => '/account'],
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
