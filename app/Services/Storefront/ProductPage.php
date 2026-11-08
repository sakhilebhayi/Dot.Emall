<?php

namespace App\Services\Storefront;

use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\Addresses\AddressRules;
use App\Services\Catalogue\CategoryTree;
use App\Services\Catalogue\ReferencePrice;
use App\Services\Community\StoreMembers;
use App\Services\Community\StoreRatings;
use App\Services\Discovery\RecommendationSet;
use App\Services\Discovery\Wishlists;
use App\Services\Shipping\ShippingOption;
use App\Services\Shipping\ShippingQuote;
use App\Services\Stores\StoreTrust;
use App\Support\Money;
use App\Support\Shop;
use App\Support\Status;
use App\Support\Vat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Everything the product page decides on the server, in one place: which variant the shopper has chosen, what that costs, what is
 * in stock, what delivery will cost to where they are, and whether they can buy at all. The view only draws these answers.
 *
 * Nothing is guessed: an unknown delivery region leaves delivery and total "not known yet", a price that depends on a choice not
 * yet made is shown as "from", a reference price exists only through ReferencePrice (the recorded 30-day low), and "only N left"
 * appears only for a real quantity of five or fewer.
 */
class ProductPage
{
    public function __construct(private readonly CategoryTree $tree, private readonly StoreMembers $members) {}

    /** Can this person see the page at all? Strangers see only a listing that is live; the store's people and the operator see it in any state. */
    public function visibleTo(Product $product, ?User $user): bool
    {
        if ($this->isLive($product)) {
            return true;
        }

        return $user !== null && ($this->members->canSpeakFor($user, $product->store) || $this->members->isOperator($user));
    }

    public function isLive(Product $product): bool
    {
        return $product->is_active && $product->status === 'approved' && $product->store->is_active;
    }

    /** Why a page the stranger could not see is showing (to the people allowed to see it). */
    public function previewState(Product $product): ?string
    {
        if ($this->isLive($product)) {
            return null;
        }

        return match (true) {
            $product->status === 'pending_approval' => 'pending_review',
            $product->status === 'rejected' => 'rejected',
            default => 'unlisted',
        };
    }

    /**
     * The buy box: choices, price, stock, delivery, alerts.
     *
     * @return array<string, mixed>
     */
    public function buy(Product $product, Request $request, ?User $user): array
    {
        $product->loadMissing('store', 'options', 'images');

        $variants = $product->variants()->where('is_active', true)->get();
        $order = $product->options->mapWithKeys(fn ($o) => [$o->name => (array) $o->values])->all();
        $requested = $this->requestedOptions($request);
        $variantId = $request->integer('variant') ?: null;

        $choices = new ProductChoices($variants, $order, $requested, $variantId);
        $variant = $choices->variant();
        $matching = $choices->matching();
        $complete = ! $choices->hasOptions() || $choices->complete();

        $prices = $matching->map(fn (ProductVariant $v) => $v->priceCents())->unique()->values();
        $priceCents = $variant?->priceCents() ?? ($prices->min() ?? Money::toCents($product->price));
        $priceFrom = $variant === null && $prices->count() > 1;

        $stock = $variant !== null ? (int) $variant->stock : (int) $matching->sum('stock');
        $stockState = $variant !== null || ! $choices->hasOptions() ? Status::stockState($stock) : ($stock > 0 ? 'in_stock' : 'out_of_stock');

        $isLive = $this->isLive($product);
        $own = $user !== null && $product->store->owner_id === $user->id;
        $canBuy = $isLive && ! $own && $variants->isNotEmpty() && $stock > 0 && ! $choices->impossible;

        $region = DeliveryRegion::current($request, $user);
        $delivery = $this->delivery($product, $priceCents, $region, $complete && ! $priceFrom);

        $reference = $variant !== null ? ReferencePrice::for($variant) : null;

        return [
            'product' => $product,
            'store' => $product->store,
            'choices' => $choices,
            'pickers' => $choices->pickers(),
            'variant' => $variant,
            'complete' => $complete,
            'missing' => $choices->missing(),
            'impossible' => $choices->impossible,
            'priceCents' => $priceCents,
            'priceFrom' => $priceFrom,
            'reference' => $reference,
            'stock' => $stock,
            'stockState' => $stockState,
            'showQuantityLeft' => $variant !== null || ! $choices->hasOptions(),
            'isLive' => $isLive,
            'own' => $own,
            'canBuy' => $canBuy,
            'maxQuantity' => max(1, min(99, $variant !== null ? $stock : (int) ($matching->max('stock') ?? 1))),
            'vatRegistered' => (bool) $product->store->vat_registered,
            'vatCents' => $product->store->vat_registered && $complete && ! $priceFrom ? Vat::includedIn($priceCents) : null,
            'delivery' => $delivery,
            'methods' => $region === null || $delivery['options'] === [] ? $this->methodFacts($product->store->shippingMethods()->where('is_active', true)->get()->all()) : [],
            'regions' => AddressRules::PROVINCES,
            'alerts' => $user ? $this->alertsFor($user, $product, $variant) : [],
            'wished' => $user ? app(Wishlists::class)->has($user, $product) : false,
            'user' => $user,
        ];
    }

    /**
     * What goes under the buy box: specs, the seller, policies, breadcrumbs.
     *
     * @return array<string, mixed>
     */
    public function details(Product $product): array
    {
        $product->loadMissing('store.owner', 'category', 'brand', 'images', 'attributeValues');
        $store = $product->store;

        $crumbs = [['label' => 'Home', 'href' => url('/')], ['label' => 'Products', 'href' => route('products.index')]];

        if ($product->category instanceof StoreCategory) {
            foreach ($this->tree->breadcrumbs($product->category) as $category) {
                $crumbs[] = ['label' => $category->name, 'href' => $this->categoryUrl($category)];
            }
        }

        $crumbs[] = ['label' => $product->name];

        return [
            'crumbs' => $crumbs,
            'specs' => $product->attributeValues->map(fn ($a) => ['label' => $a->label, 'value' => trim($a->value.($a->unit ? ' '.$a->unit : ''))])->all(),
            'brand' => $product->brand?->name,
            'trust' => StoreTrust::for($store),
            'storeRating' => app(StoreRatings::class)->summary($store),
            'returnDays' => (int) config('emall.returns.window_days', 7),
        ];
    }

    /**
     * The combined add-to-cart under "Bought together": this product plus the items that need no choice and are in stock. Anything that comes
     * in several options is named and left out, never guessed. Null when nothing could be added besides the product itself.
     *
     * @param  array<string, mixed>  $buy
     * @return array{ids: list<int>, count: int, total: int, total_from: bool, skipped: list<string>}|null
     */
    public function bundle(RecommendationSet $set, array $buy): ?array
    {
        $products = collect($set->items)->map(fn ($item) => $item->product)->unique('id')->values();
        $counts = ProductVariant::query()->whereIn('product_id', $products->pluck('id'))->where('is_active', true)->get(['product_id', 'price', 'stock'])->groupBy('product_id');

        $ids = [];
        $skipped = [];
        $total = (int) $buy['priceCents'];

        foreach ($products as $p) {
            $variants = $counts->get($p->id, collect());

            if ($variants->count() === 1 && $variants->first()->stock > 0) {
                $ids[] = $p->id;
                $total += Money::toCents($variants->first()->price);
            } elseif ($variants->count() > 1) {
                $skipped[] = $p->name;
            }
        }

        return $ids === [] ? null : ['ids' => $ids, 'count' => count($ids) + 1, 'total' => $total, 'total_from' => (bool) $buy['priceFrom'], 'skipped' => $skipped];
    }

    public function categoryUrl(StoreCategory $category): string
    {
        return Route::has('categories.show') ? route('categories.show', $category->slug) : route('products.index', ['category' => $category->id]);
    }

    /**
     * schema.org markup for search engines. AggregateRating only when the product has real published reviews.
     *
     * @param  array<string, mixed>  $buy
     * @return array<string, mixed>
     */
    public function structuredData(Product $product, array $buy): array
    {
        $product->loadMissing('images', 'brand', 'store');
        $url = route('products.show', $product);
        $available = $buy['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
        $currency = Shop::currency();

        $offer = $buy['priceFrom']
            ? [
                '@type' => 'AggregateOffer',
                'priceCurrency' => $currency,
                'lowPrice' => Money::fromCents($buy['priceCents']),
                'highPrice' => Money::fromCents((int) $buy['choices']->variants->map(fn ($v) => $v->priceCents())->max()),
                'offerCount' => $buy['choices']->variants->count(),
                'availability' => $available,
                'url' => $url,
            ]
            : [
                '@type' => 'Offer',
                'priceCurrency' => $currency,
                'price' => Money::fromCents($buy['priceCents']),
                'availability' => $available,
                'itemCondition' => 'https://schema.org/NewCondition',
                'url' => $url,
            ];

        $offer['seller'] = ['@type' => 'Organization', 'name' => $product->store->name];

        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => $product->description ? Str::limit(trim(strip_tags($product->description)), 300) : null,
            'sku' => $buy['variant']?->sku ?: $product->sku,
            'url' => $url,
            'image' => $product->images->map(fn ($i) => $i->url())->all() ?: null,
            'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand->name] : null,
            'offers' => $offer,
        ], fn ($v) => $v !== null && $v !== '');

        if ((int) $product->rating_count > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $product->rating_average, 1, '.', ''),
                'reviewCount' => (int) $product->rating_count,
            ];
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function requestedOptions(Request $request): array
    {
        $asked = $request->query('opt');

        if (! is_array($asked)) {
            return [];
        }

        $out = [];

        foreach ($asked as $name => $value) {
            if (is_string($name) && is_string($value) && mb_strlen($name) <= 60 && mb_strlen($value) <= 120) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array{province: string, postal_code: ?string, source: string, label: string}|null  $region
     * @return array<string, mixed>
     */
    private function delivery(Product $product, int $priceCents, ?array $region, bool $priceKnown): array
    {
        $out = ['region' => $region, 'known' => false, 'option' => null, 'options' => [], 'cannotDeliver' => false, 'freeOver' => null];

        if ($region === null) {
            return $out;
        }

        $quote = ShippingQuote::for([$product->store_id => $priceCents], DeliveryRegion::region($region))[$product->store_id] ?? null;

        if ($quote === null) {
            return $out;
        }

        $out['options'] = $quote->options;

        if (! $quote->canDeliver()) {
            $out['cannotDeliver'] = true;

            return $out;
        }

        // The label quotes the cheapest way to have it delivered; collecting it yourself is listed with the other options.
        $deliverable = array_values(array_filter($quote->options, fn (ShippingOption $o) => ! $o->isCollection()));
        $option = $deliverable[0] ?? $quote->cheapest();

        $out['known'] = $priceKnown;
        $out['option'] = $option;
        $out['freeOver'] = collect($quote->options)->first(fn (ShippingOption $o) => $o->freeOverCents !== null)?->freeOverCents;

        return $out;
    }

    /**
     * The seller's delivery methods as plain facts, whatever the region.
     *
     * @param  list<ShippingMethod>  $methods
     * @return list<array{name: string, price: string, window: string}>
     */
    public function methodFacts(array $methods): array
    {
        return array_map(function (ShippingMethod $m) {
            $type = $m->type->value;
            $price = match ($type) {
                'collection' => 'Collect from the seller',
                'free_over' => 'Free over '.Shop::money(Money::toCents($m->free_over)).', otherwise '.Shop::money(Money::toCents($m->price)),
                default => Shop::money(Money::toCents($m->price)),
            };
            $window = $type === 'collection'
                ? ($m->handling_days > 0 ? "Ready in {$m->handling_days} working ".Str::plural('day', $m->handling_days) : 'Ready when the seller confirms')
                : "{$m->window_min_days} to {$m->window_max_days} working days in transit, after {$m->handling_days} ".Str::plural('day', $m->handling_days).' to prepare';

            return ['name' => $m->name, 'price' => $price, 'window' => $window];
        }, $methods);
    }

    /**
     * @return array<string, ProductAlert>
     */
    private function alertsFor(User $user, Product $product, ?ProductVariant $variant): array
    {
        $key = $variant !== null && $product->options->isNotEmpty() ? $variant->id : 0;

        return ProductAlert::query()->where('user_id', $user->id)->where('product_id', $product->id)->where('active', true)->where('variant_key', $key)
            ->get()->keyBy('kind')->all();
    }
}
