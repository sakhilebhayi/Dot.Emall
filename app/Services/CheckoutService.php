<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Addresses\AddressBook;
use App\Services\Catalogue\Inventory;
use App\Services\Catalogue\OutOfStock;
use App\Services\Guests\GuestOrderAccess;
use App\Services\Notifications\OrderNotifier;
use App\Services\Paystack\PaystackConfig;
use App\Services\Shipping\Region;
use App\Services\Shipping\ShippingOption;
use App\Services\Shipping\ShippingQuote;
use App\Support\Money;
use App\Support\Shop;
use App\Support\Throttles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a cart (an account's, or a guest's lines) into an order. Taking the payment is NOT done here: the order that
 * comes out is pending and unpaid, and either
 *   - Paystack is configured: it is flagged requires_payment, the caller sends the buyer to pay
 *     (App\Services\PaymentService), and sellers hear nothing and cannot fulfil it until it is paid; or
 *   - Paystack is not configured: it is exactly the old "pending, unpaid" order (requires_payment = false), sellers
 *     are told at once. In production this is refused (D-1, config emall.checkout.requires_gateway).
 *
 * Everything that must hold together happens in one transaction: product rows, then variant rows, are locked (in id
 * order, so concurrent checkouts that share products cannot deadlock), stock is re-checked and taken with a
 * WHERE-guarded update (App\Services\Catalogue\Inventory, the only protection under SQLite, where row locks are
 * no-ops, and a StockMovement is written), the promo row is locked and its use counted, each seller group gets its
 * delivery method priced server-side (ShippingQuote), and each line is written with a snapshot of what was bought.
 * OrderPlaced is dispatched only after the commit.
 *
 * `expected_total_cents` (or `expected_total`) is the total the buyer was SHOWN. When given and different from the
 * total recomputed here the order is not placed and PriceChangedException says so.
 */
class CheckoutService
{
    public function __construct(
        private readonly OrderPricing $pricing,
        private readonly PaystackConfig $paystack,
        private readonly Inventory $inventory,
        private readonly ShippingQuote $shipping,
        private readonly GuestOrderAccess $guestAccess,
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $input  shipping_address (legacy one-line string) or address_id (saved address) or address (fields);
     *                                       shipping_name, shipping_phone, promo_code, shipping_methods (store id => method id),
     *                                       expected_total_cents
     * @param  string|null  $token  the form's single-use token; the same token never places two orders
     * @param  string|null  $ip  the buyer's address, for the promo guess budget
     *
     * @throws CheckoutException
     */
    public function place(User $buyer, array $input, ?string $token = null, ?string $ip = null): Order
    {
        return $this->run($buyer, null, null, $input, $token, $ip);
    }

    /**
     * A guest checkout (D-9): lines come from the session cart, the e-mail is where the order's magic link goes.
     *
     * @param  array<int, array{variant_id: int, quantity: int}>  $lines
     * @param  array<string, mixed>  $input
     *
     * @throws CheckoutException
     */
    public function placeAsGuest(string $email, array $lines, array $input, ?string $token = null, ?string $ip = null): Order
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CheckoutException('Enter a valid e-mail address so we can send your order link.');
        }

        return $this->run(null, $email, $lines, $input, $token, $ip);
    }

    private function run(?User $buyer, ?string $guestEmail, ?array $guestLines, array $input, ?string $token, ?string $ip): Order
    {
        // Idempotency comes first: a replay arrives with an already-emptied
        // cart, which must not read as "your cart is empty".
        if ($token !== null && ($existing = $this->orderForToken($buyer, $guestEmail, $token)) !== null) {
            return $existing;
        }

        if (config('emall.checkout.requires_gateway') && ! $this->paystack->isUsable()) {
            throw new CheckoutException('Payments are not open yet, so orders cannot be placed right now. Nothing was charged.');
        }

        $promoInput = trim((string) ($input['promo_code'] ?? ''));

        if ($promoInput !== '') {
            $this->guardPromoBudget($buyer, $guestEmail, $ip);
        }

        if ($buyer !== null) {
            $cartItemIds = CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->pluck('id');

            if ($cartItemIds->isEmpty()) {
                throw new CheckoutException('Your cart is empty.');
            }
        } else {
            $cartItemIds = collect();

            if ($guestLines === []) {
                throw new CheckoutException('Your cart is empty.');
            }
        }

        try {
            $order = $this->createOrder($buyer, $guestEmail, $guestLines, $input, $cartItemIds, $token);
        } catch (PromoRejectedException $e) {
            // Counted here, after the transaction has rolled back, so the
            // guess is remembered whatever the cache store is.
            foreach ($this->promoKeys($buyer, $guestEmail, $ip) as $key) {
                RateLimiter::hit($key, Throttles::PROMO_WINDOW_SECONDS);
            }

            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            // Two submissions of the same token raced past the check above;
            // the unique index let exactly one through.
            $existing = $token !== null ? $this->orderForToken($buyer, $guestEmail, $token) : null;

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        // After the commit, not inside it: a listener must never believe an
        // order exists that a later failure in the same transaction undid.
        // An order that must be paid first tells its sellers when it IS paid
        // (OrderPaid), not now.
        if (! $order->requires_payment) {
            event(new OrderPlaced($order));
        }

        $this->notifier->orderPlaced($order);

        return $order;
    }

    private function orderForToken(?User $buyer, ?string $guestEmail, string $token): ?Order
    {
        return Order::query()
            ->when($buyer !== null, fn ($q) => $q->where('user_id', $buyer->id), fn ($q) => $q->whereNull('user_id')->where('guest_email', $guestEmail))
            ->where('idempotency_key', $token)
            ->first();
    }

    /** @return array<int, string> */
    private function promoKeys(?User $buyer, ?string $guestEmail, ?string $ip): array
    {
        return [
            Throttles::promoUserKey($buyer?->id ?? 'guest:'.sha1((string) $guestEmail)),
            Throttles::promoIpKey($ip),
        ];
    }

    private function guardPromoBudget(?User $buyer, ?string $guestEmail, ?string $ip): void
    {
        foreach ($this->promoKeys($buyer, $guestEmail, $ip) as $key) {
            if (RateLimiter::tooManyAttempts($key, Throttles::PROMO_MAX_FAILURES)) {
                $seconds = RateLimiter::availableIn($key);

                throw new CheckoutException("Too many promo code attempts. Try again in {$seconds} seconds, or place the order without a code.");
            }
        }
    }

    private function createOrder(?User $buyer, ?string $guestEmail, ?array $guestLines, array $input, Collection $cartItemIds, ?string $token): Order
    {
        return DB::transaction(function () use ($buyer, $guestEmail, $guestLines, $input, $cartItemIds, $token) {
            if ($buyer !== null) {
                // Serialises this buyer's checkouts, so the unpaid-order cap below cannot be raced past.
                User::query()->lockForUpdate()->find($buyer->id);
            }

            $wanted = $buyer !== null
                ? CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->whereIn('id', $cartItemIds)->get()
                    ->map(fn (CartItem $item) => ['variant_id' => $item->variant_id, 'product_id' => $item->product_id, 'quantity' => (int) $item->quantity])
                : collect($guestLines)->map(fn ($row) => ['variant_id' => (int) $row['variant_id'], 'product_id' => null, 'quantity' => max(1, (int) $row['quantity'])]);

            // Legacy cart rows without a variant fall back to the product's default variant.
            $wanted = $wanted->map(function (array $row) {
                if ($row['variant_id'] === null && $row['product_id'] !== null) {
                    $row['variant_id'] = ProductVariant::query()->where('product_id', $row['product_id'])->orderByDesc('is_default')->orderBy('id')->value('id');
                }

                return $row;
            })->filter(fn (array $row) => $row['variant_id'] !== null && $row['quantity'] > 0);

            // The same variant twice (a guest's session array cannot, a bad caller could): one line.
            $wanted = $wanted->groupBy('variant_id')->map(fn (Collection $rows) => ['variant_id' => (int) $rows->first()['variant_id'], 'quantity' => (int) $rows->sum('quantity')])->values();

            if ($wanted->isEmpty()) {
                throw new CheckoutException('Your cart is empty.');
            }

            if ($wanted->sum('quantity') > 500 || $wanted->count() > 100) {
                throw new CheckoutException('That is more than one order can hold. Split it into two orders.');
            }

            $variantIds = $wanted->pluck('variant_id')->sort()->values();
            $productIds = ProductVariant::query()->whereIn('id', $variantIds)->pluck('product_id')->unique()->sort()->values();

            // Locks: products in id order, then variants in id order, always (the seller edit takes them the same way).
            $lockedProducts = Product::query()->with('store')->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedVariants = ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $lines = collect();

            foreach ($wanted as $row) {
                $variant = $lockedVariants->get($row['variant_id']);
                $product = $variant ? $lockedProducts->get($variant->product_id) : null;

                if (! $variant || ! $product || ! $variant->is_active || ! $product->is_active || $product->status !== 'approved' || ! $product->store?->is_active) {
                    $name = $product?->name ?? 'An item in your cart';
                    throw new CheckoutException("\"{$name}\" is no longer available. Remove it from your cart to continue.");
                }

                if ($row['quantity'] > $variant->stock) {
                    throw new OutOfStock($variant, $row['quantity']);
                }

                $lines->push(['variant' => $variant, 'product' => $product, 'quantity' => $row['quantity']]);
            }

            $this->guardUnpaidCap($buyer, $guestEmail);

            $promo = $this->resolvePromo($input['promo_code'] ?? null, $lines);
            [$snapshot, $region, $name, $phone, $addressLine] = $this->destination($buyer, $input);

            // One delivery choice per seller group, priced here and nowhere else.
            $byStore = $lines->groupBy(fn (array $l) => $l['product']->store_id);
            $itemsByStore = $byStore->map(fn (Collection $group) => $group->sum(fn (array $l) => $l['quantity'] * Money::toCents($l['variant']->price)))->all();
            $choices = (array) ($input['shipping_methods'] ?? []);
            $options = [];

            foreach ($itemsByStore as $storeId => $itemsCents) {
                $choice = $choices[$storeId] ?? null;
                $options[$storeId] = $this->shipping->resolve((int) $storeId, $choice === null || $choice === '' ? null : (int) $choice, (int) $itemsCents, $region);
            }

            $order = Order::create([
                'user_id' => $buyer?->id,
                'guest_email' => $guestEmail,
                'status' => OrderStatus::Pending,
                // Stamped for life: a later SHOP_CURRENCY change never relabels this order.
                'currency' => Shop::currency(),
                'requires_payment' => $this->paystack->isConfigured(),
                'payment_status' => PaymentStatus::Unpaid,
                'subtotal' => 0,
                'total' => 0,
                'discount_amount' => 0,
                'promo_code' => $promo?->code,
                'promo_code_id' => $promo?->id,
                'promo_store_id' => $promo?->store_id,
                'promo_discount_type' => $promo?->discount_type,
                'promo_discount_value' => $promo?->discount_value,
                'promo_free_shipping' => (bool) ($promo?->free_shipping),
                'shipping_name' => $name ?: ($buyer?->name ?? $guestEmail),
                'shipping_address' => $addressLine,
                'shipping_phone' => $phone,
                'shipping_snapshot' => $snapshot,
                'idempotency_key' => $token,
            ]);

            $groups = [];

            foreach ($byStore as $storeId => $group) {
                $store = $group->first()['product']->store;
                /** @var ShippingOption $option */
                $option = $options[$storeId];
                $free = $promo !== null && $promo->free_shipping && (int) $promo->store_id === (int) $storeId;

                $groups[$storeId] = OrderGroup::create([
                    'order_id' => $order->id,
                    'store_id' => $store->id,
                    'seller_id' => $store->owner_id,
                    'store_name' => $store->name,
                    'shipping_method_id' => $option->methodId,
                    'method_name' => $option->name,
                    'method_type' => $option->type,
                    'delivery_cents' => $free ? 0 : $option->priceCents,
                    'vat_registered' => (bool) $store->vat_registered,
                    'handling_days' => $option->handlingDays,
                    'window_min_days' => $option->windowMinDays,
                    'window_max_days' => $option->windowMaxDays,
                    'estimated_from' => $option->estimatedFrom?->toDateString(),
                    'estimated_to' => $option->estimatedTo?->toDateString(),
                    'status' => OrderStatus::Pending->value,
                ]);
            }

            foreach ($lines as $line) {
                /** @var ProductVariant $variant */
                $variant = $line['variant'];
                $product = $line['product'];

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'order_group_id' => $groups[$product->store_id]->id,
                    'product_id' => $product->id,
                    'variant_id' => $variant->id,
                    'product_name' => $product->name,
                    'variant_label' => $variant->label(),
                    'sku' => $variant->sku,
                    'store_id' => $product->store_id,
                    'store_name' => $product->store->name,
                    'seller_id' => $product->store->owner_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $variant->price,
                    'total' => Money::fromCents(Money::toCents($variant->price) * $line['quantity']),
                    'status' => OrderStatus::Pending,
                ]);

                // Re-checks stock at write time: even without a real row
                // lock (SQLite) this can never drive stock below zero.
                $this->inventory->take($variant, $line['quantity'], 'sale', $buyer?->id, $order->id, $item->id);
            }

            $this->pricing->apply($order);

            if (isset($input['expected_total_cents']) || isset($input['expected_total'])) {
                $expected = isset($input['expected_total_cents'])
                    ? (int) $input['expected_total_cents']
                    : Money::toCents($input['expected_total']);

                if ($expected !== $order->totalCents()) {
                    throw new PriceChangedException($expected, $order->totalCents());
                }
            }

            if ($promo !== null) {
                $promo->increment('used_count');
            }

            if ($buyer !== null) {
                CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->whereIn('id', $cartItemIds)->delete();
            } else {
                $this->guestAccess->issue($order);
            }

            return $order;
        });
    }

    /**
     * D-4: only so many payment-required orders may be waiting for money at once, per buyer or guest e-mail.
     */
    private function guardUnpaidCap(?User $buyer, ?string $guestEmail): void
    {
        if (! $this->paystack->isConfigured()) {
            return;
        }

        $max = (int) config('emall.checkout.max_unpaid_orders', 3);

        if ($max < 1) {
            return;
        }

        $open = Order::query()
            ->when($buyer !== null, fn ($q) => $q->where('user_id', $buyer->id), fn ($q) => $q->whereNull('user_id')->where('guest_email', $guestEmail))
            ->where('requires_payment', true)
            ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Pending->value, PaymentStatus::Failed->value])
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->count();

        if ($open >= $max) {
            throw new CheckoutException("You already have {$open} orders waiting for payment. Pay for one, or let it expire, before placing another.");
        }
    }

    /**
     * The delivery address: a saved address, free-form address fields, or (older callers) one line of text.
     *
     * @return array{0: ?array<string, ?string>, 1: ?Region, 2: ?string, 3: ?string, 4: string} snapshot, region, name, phone, one-line address
     */
    private function destination(?User $buyer, array $input): array
    {
        $snapshot = null;

        if ($buyer !== null && filled($input['address_id'] ?? null)) {
            $address = Address::query()->where('user_id', $buyer->id)->find((int) $input['address_id']);

            if ($address === null) {
                throw new CheckoutException('That saved address was not found. Choose another.');
            }

            $snapshot = $address->snapshot();
        } elseif (is_array($input['address'] ?? null)) {
            try {
                $snapshot = collect(app(AddressBook::class)->validated($input['address']))->only(array_keys((new Address)->snapshot()))->all();
            } catch (ValidationException $e) {
                throw new CheckoutException(collect($e->errors())->flatten()->first() ?? 'Check the delivery address.');
            }
        }

        if ($snapshot !== null) {
            $snapshot['country'] = $snapshot['country'] ?: 'ZA';

            return [
                $snapshot, Region::from($snapshot), $snapshot['recipient_name'],
                $snapshot['phone'] !== null ? mb_substr((string) $snapshot['phone'], 0, 40) : null,
                mb_substr(Address::oneLine($snapshot), 0, 255),
            ];
        }

        $line = trim((string) ($input['shipping_address'] ?? ''));

        if ($line === '') {
            throw new CheckoutException('Enter a delivery address.');
        }

        return [null, null, $input['shipping_name'] ?? null, $input['shipping_phone'] ?? null, $line];
    }

    /**
     * Finds the code among the stores actually in the cart. Codes are unique
     * per store, not globally, so the same text can exist in several stores;
     * only a store whose items are being bought can supply the discount, and
     * if more than one carted store has it the buyer gets the larger saving
     * (ties go to the lower store id, so the answer is deterministic).
     *
     * @param  Collection<int, array{variant: ProductVariant, product: Product, quantity: int}>  $lines
     *
     * @throws PromoRejectedException
     */
    private function resolvePromo(?string $input, Collection $lines): ?PromoCode
    {
        $input = $input === null ? '' : trim($input);

        if ($input === '') {
            return null;
        }

        $storeIds = $lines->map(fn (array $l) => $l['product']->store_id)->unique()->values();

        $candidates = PromoCode::query()
            ->whereIn('store_id', $storeIds)
            ->whereRaw('UPPER(code) = ?', [Str::upper($input)])
            ->orderBy('store_id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (PromoCode $promo) => $promo->isRedeemable());

        $best = null;
        $bestDiscount = 0;

        foreach ($candidates as $promo) {
            $eligibleCents = $lines
                ->filter(fn (array $l) => $l['product']->store_id === $promo->store_id)
                ->sum(fn (array $l) => $l['quantity'] * Money::toCents($l['variant']->price));

            $discount = Money::discountCents($promo->discount_type, $promo->discount_value, $eligibleCents);

            if ($eligibleCents > 0 && $discount > $bestDiscount) {
                $best = $promo;
                $bestDiscount = $discount;
            } elseif ($eligibleCents > 0 && $best === null) {
                $best = $promo;
            }
        }

        if ($best === null) {
            throw new PromoRejectedException;
        }

        return $best;
    }
}
