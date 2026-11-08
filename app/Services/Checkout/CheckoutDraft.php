<?php

namespace App\Services\Checkout;

use App\Models\Address;
use App\Models\User;
use App\Services\Addresses\AddressBook;
use App\Services\Cart\CartQuote;
use App\Services\Cart\QuoteResult;
use Illuminate\Support\Collection;
use App\Services\Shipping\Region;

/**
 * What a shopper has told us so far, kept in the session so nothing typed is lost: the promo code, the delivery estimate region,
 * the chosen delivery method of each seller, and (at checkout) the e-mail, phone and address. It holds REQUESTS, never prices:
 * every figure is recomputed by CartQuote and CheckoutService.
 */
class CheckoutDraft
{
    public const KEY = 'emall.checkout';

    public const SEEN_PRICES = 'emall.cart_seen_prices';

    /** @return array<string, mixed> */
    public function all(): array
    {
        $draft = session(self::KEY, []);

        return is_array($draft) ? $draft : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** @param  array<string, mixed>  $values */
    public function merge(array $values): void
    {
        session([self::KEY => array_merge($this->all(), $values)]);
    }

    public function forget(?string $key = null): void
    {
        if ($key === null) {
            session()->forget(self::KEY);

            return;
        }

        $draft = $this->all();
        unset($draft[$key]);
        session([self::KEY => $draft]);
    }

    /**
     * The quote the pages show. Until the shopper chooses, each seller's cheapest way is NOT assumed to be collection: when the cheapest
     * option is "collect it yourself" and the seller also delivers, delivery is the starting choice (the radio shows it and the shopper can
     * move to collection with one press). The total the page shows and the one checkout checks are the same quote.
     *
     * @param  Collection<int, \App\Services\Cart\CartLine>  $lines
     */
    public function quote(CartQuote $quotes, Collection $lines, ?User $user, ?string $promo = null, bool $usePromo = true): QuoteResult
    {
        $region = $this->region($user);
        $promo = $usePromo ? ($promo ?? $this->promo()) : null;
        $methods = $this->methods();
        $result = $quotes->quote($lines, $region, $promo, $methods);
        $fill = [];

        foreach ($result->groups as $storeId => $group) {
            if (isset($methods[$storeId]) || $group['selected'] === null || ! $group['selected']->isCollection()) {
                continue;
            }

            $delivery = collect($group['options'])->first(fn ($o) => ! $o->isCollection() && $o->methodId !== null);

            if ($delivery !== null) {
                $fill[$storeId] = $delivery->methodId;
            }
        }

        return $fill === [] ? $result : $quotes->quote($lines, $region, $promo, $fill + $methods);
    }

    public function promo(): ?string
    {
        $code = trim((string) $this->get('promo_code', ''));

        return $code === '' ? null : $code;
    }

    /** @return array<int, int> store id => method id */
    public function methods(): array
    {
        $methods = $this->get('shipping_methods', []);

        return is_array($methods) ? collect($methods)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->all() : [];
    }

    /**
     * Where the parcels are going, as far as delivery rules care: a saved address, a typed one, the estimate form on the cart, or
     * the person's default address. Null when nothing is known (then the page says so instead of guessing).
     *
     * @param  array<string, mixed>|null  $override  values typed in this request, which win over the saved draft
     */
    public function region(?User $user, ?array $override = null): ?Region
    {
        $source = $override ?? $this->all();

        if ($user !== null && filled($source['address_id'] ?? null)) {
            $address = Address::query()->where('user_id', $user->id)->find((int) $source['address_id']);

            if ($address !== null) {
                return Region::from($address);
            }
        }

        $typed = is_array($source['address'] ?? null) ? $source['address'] : [];

        if (filled($typed['province'] ?? null) || filled($typed['postal_code'] ?? null)) {
            return Region::from($typed + ['country' => 'ZA']);
        }

        $estimate = is_array($source['region'] ?? null) ? $source['region'] : [];

        if (filled($estimate['province'] ?? null) || filled($estimate['postal_code'] ?? null)) {
            return Region::from($estimate + ['country' => 'ZA']);
        }

        if ($user !== null && ! array_key_exists('address_id', $source)) {
            $default = app(AddressBook::class)->defaultShipping($user);

            return $default !== null ? Region::from($default) : null;
        }

        return null;
    }
}
