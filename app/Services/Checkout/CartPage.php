<?php

namespace App\Services\Checkout;

use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartLine;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartQuote;
use App\Services\Cart\QuoteResult;
use App\Services\Discovery\Recommender;
use App\Services\Shipping\Region;
use App\Support\Actor;
use App\Support\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Everything the cart page draws, from one place: the lines, the server-side quote (delivery per seller, promo, VAT, total), what
 * changed since the shopper last looked (price or availability, said out loud and never fixed silently) and, for an empty cart,
 * suggestions from real data. Nothing here is believed from the browser.
 */
class CartPage
{
    public function __construct(
        private readonly CartManager $cart,
        private readonly CartQuote $quote,
        private readonly CheckoutDraft $draft,
        private readonly Recommender $recommender,
    ) {}

    /** @return array<string, mixed> */
    public function for(Request $request): array
    {
        $user = $request->user();
        $lines = $this->cart->lines($user);
        $region = $this->draft->region($user);
        $result = $this->quote->quote($lines, $region, $this->draft->promo(), $this->draft->methods());

        // A code that stopped applying (expired, used up, the cart changed) is dropped out loud, not left in a silent state.
        $promoNotice = null;

        if ($this->draft->promo() !== null && $result->promo === null) {
            $promoNotice = 'The code '.strtoupper((string) $this->draft->promo()).' no longer applies to the items in your cart, so it was removed.';
            $this->draft->forget('promo_code');
            $result = $this->quote->quote($lines, $region, null, $this->draft->methods());
        }

        $changes = $this->priceChanges($lines);

        return [
            'lines' => $lines,
            'quote' => $result,
            'region' => $region,
            'estimate' => $this->draft->get('region', []),
            'promoNotice' => $promoNotice,
            'changes' => $changes,
            'provinces' => \App\Services\Addresses\AddressRules::PROVINCES,
            'parcelCount' => count($result->groups),
            'signedIn' => $user !== null,
            'suggestions' => $lines->isEmpty() ? $this->suggestions($request) : collect(),
        ];
    }

    /**
     * Prices that differ from what the shopper last saw in their cart. The first look records the prices; a later look compares.
     * "Keep the new prices" (cart.prices.accept) records them again. Nothing about the cart itself changes.
     *
     * @param  Collection<int, CartLine>  $lines
     * @return array<int, array{name: string, from: int, to: int}>
     */
    private function priceChanges(Collection $lines): array
    {
        $seen = session(CheckoutDraft::SEEN_PRICES, []);
        $seen = is_array($seen) ? $seen : [];
        $changes = [];

        foreach ($lines as $line) {
            $id = $line->variant->id;
            $now = $line->unitCents();

            if (! array_key_exists($id, $seen)) {
                $seen[$id] = $now;

                continue;
            }

            if ((int) $seen[$id] !== $now) {
                $changes[$id] = ['name' => $this->nameOf($line), 'from' => (int) $seen[$id], 'to' => $now];
            }
        }

        session([CheckoutDraft::SEEN_PRICES => array_intersect_key($seen, array_flip($lines->map(fn (CartLine $l) => $l->variant->id)->all()))]);

        return $changes;
    }

    public function acceptPrices(?User $user): void
    {
        $seen = [];

        foreach ($this->cart->lines($user) as $line) {
            $seen[$line->variant->id] = $line->unitCents();
        }

        session([CheckoutDraft::SEEN_PRICES => $seen]);
    }

    private function nameOf(CartLine $line): string
    {
        return $line->variant->label() ? "{$line->product->name} ({$line->variant->label()})" : $line->product->name;
    }

    /**
     * What to show instead of an empty cart: the shopper's own recently viewed products when there are any, else what they bought
     * before, else the newest listings (said to be exactly that).
     *
     * @return Collection<int, Product>
     */
    private function suggestions(Request $request): Collection
    {
        $actor = Actor::forRequest($request);

        foreach ([fn () => $this->recommender->recentlyViewed($actor), fn () => $actor->isSignedIn() ? $this->recommender->becauseYouBought($actor) : null] as $make) {
            try {
                $set = $make();
            } catch (\Throwable) {
                $set = null;
            }

            if ($set !== null && ! $set->isEmpty()) {
                return collect([
                    'title' => $set->title, 'basis' => $set->basis,
                    'products' => collect($set->items)->map(fn ($i) => $i->product)->take(4)->values(),
                ]);
            }
        }

        $latest = Product::query()->browsable()->with(['store', 'images'])->latest('id')->limit(4)->get();

        return $latest->isEmpty() ? collect() : collect(['title' => 'New on Emall', 'basis' => 'The latest listings from our sellers.', 'products' => $latest]);
    }

    public static function describePromo(QuoteResult $quote): ?string
    {
        $promo = $quote->promo;

        if ($promo === null) {
            return null;
        }

        $what = $promo->discount_type === 'percent' ? rtrim(rtrim((string) $promo->discount_value, '0'), '.').'% off' : Shop::amount($promo->discount_value).' off';
        $store = $quote->groups[$promo->store_id]['store_name'] ?? $promo->store?->name ?? 'one seller';
        $extra = $promo->free_shipping ? ' and free delivery' : '';

        return "{$what}{$extra}, on items from {$store} only";
    }
}
