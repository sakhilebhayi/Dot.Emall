{{--
  The buy box of the product page. Everything in it depends on the choices made (which variant, which region), so it is also served on its own
  (header X-PDP-Fragment: buy) and swapped in by resources/js/product.js when a choice changes. All figures come from App\Services\Storefront\ProductPage.
--}}
@php
    $product->loadMissing('images');
    // Estimates are printed with words, not dashes (DESIGN.md copy voice): "9 to 11 Nov".
    $dateRange = fn (string $text) => str_replace(['–', '—'], [' to ', ', '], $text);
    $d = $delivery;
    $opt = $d['option'];
    $needs = $missing ? implode(' and ', array_map('strtolower', $missing)) : null;
    $deliveryCents = null;
    $deliveryNote = null;
    $totalCents = null;
    $pending = 'Choose where it is going to see this';

    if ($impossible) {
        $pending = 'That choice is not made';
    } elseif (! $complete || $priceFrom) {
        $pending = 'Choose '.($needs ?? 'an option').' first';
    } elseif ($d['cannotDeliver']) {
        $pending = 'Not delivered to '.$d['region']['province'];
    } elseif ($opt) {
        if ($opt->isArranged()) {
            $pending = 'Arranged with the seller after you order. Emall adds nothing';
            $totalCents = $priceCents;
        } else {
            $deliveryCents = $opt->priceCents;
            $deliveryNote = $opt->name.($opt->isCollection() ? '' : '. '.preg_replace('/\s*\(.*$/', '', $dateRange($opt->estimateLabel)));
        }
    }

    $imageIndex = null;
    if ($variant?->image_id) { $imageIndex = $product->images->search(fn ($i) => $i->id === $variant->image_id); $imageIndex = $imageIndex === false ? null : $imageIndex; }
    $hasOptions = $choices->hasOptions();
    $cartErrors = $errors->getBag('cart');
    $alertErrors = $errors->getBag('alert');
    $pickError = $cartErrors->first('variant_id');
    $titleLine = $variant?->label() ?? ($priceFrom ? 'From' : null);
    $returnTo = request()->getRequestUri();
    $buyable = $canBuy && $complete;
@endphp
<div id="buy" class="pdp-buy" data-pdp-buy data-variant-id="{{ $variant?->id }}" @if ($imageIndex !== null) data-image-index="{{ $imageIndex }}" @endif>
    @if (session('cart_link'))
        <x-ds.alert tone="pos" class="pdp-incart">
            <span>Added. </span><a href="{{ route('cart.index') }}">View your cart</a>
        </x-ds.alert>
    @endif

    <x-ds.price-label
        :price-cents="$priceCents"
        :from="$store->name"
        :title="$titleLine"
        :previous-cents="$reference['cents'] ?? null"
        :unit="$priceFrom ? 'Lowest price. The price you pay depends on your choice.' : null"
        :delivery-cents="$deliveryCents"
        :delivery-note="$deliveryNote"
        :delivery-pending="$pending"
        :total-cents="$totalCents"
        :vat-included="$vatRegistered"
    />

    {{-- Choices: a plain GET form, so it works with no script; the script swaps this box in place instead of reloading. --}}
    @if ($hasOptions)
        <form method="GET" action="{{ route('products.show', $product) }}#buy" class="pdp-options" id="options" data-pdp-choose>
            @foreach ($pickers as $picker)
                <x-ds.option-picker :name="'opt['.$picker['name'].']'" :label="$picker['name']" :options="$picker['options']" :selected="$picker['selected']" :class="$pickError && ! $picker['selected'] ? 'has-error' : ''" />
            @endforeach
            <noscript><button type="submit" class="ds-btn ds-btn--quiet ds-btn--sm">Show the price for this choice</button></noscript>
        </form>
        @if ($impossible)
            <x-ds.alert tone="cau">That combination is not made. Choose another size or colour; the ones that cannot go together are crossed out.</x-ds.alert>
        @endif
    @endif

    <p class="pdp-error" id="choose-error" role="alert" @if (! $pickError) hidden @endif><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $pickError ?: 'Choose '.($needs ?? 'an option').' first.' }}</span></p>

    {{-- Stock: a word, a shape, and the real number only when it is five or fewer. --}}
    <div class="ds-pdp__stock pdp-stock">
        @if ($complete && ! $impossible)
            <x-ds.status kind="product" :state="$stockState" :quantity="$stock" />
            @if ($stockState === 'in_stock')<span class="ds-muted">The seller ships it, not Emall.</span>@endif
        @elseif ($stockState === 'out_of_stock')
            <x-ds.status kind="product" state="out_of_stock" />
        @endif
    </div>

    @if (! $isLive)
        <x-ds.alert tone="info">This listing is not on sale right now, so it cannot be bought.</x-ds.alert>
    @elseif ($own)
        <x-ds.alert tone="info">This is your own listing, so it cannot be added to a cart.</x-ds.alert>
    @elseif ($variant === null && ! $hasOptions)
        <x-ds.alert tone="info">This product has nothing to buy right now.</x-ds.alert>
    @elseif ($stockState === 'out_of_stock' && $complete)
        <x-ds.button size="lg" block disabled icon="cart">Out of stock</x-ds.button>
    @else
        <form method="POST" action="{{ route('products.cart', $product) }}" class="ds-pdp__form pdp-add" id="buy-form" data-pdp-add @if ($hasOptions && ! $variant) data-needs="{{ $needs }}" @endif>
            @csrf
            <input type="hidden" name="variant_id" value="{{ $variant?->id }}">
            <x-ds.quantity-stepper :max="$maxQuantity" id="pdp-qty" />
            <x-ds.button type="submit" size="lg" icon="cart" class="ds-pdp__add">Add to cart</x-ds.button>
        </form>
        @if ($cartErrors->has('quantity'))<p class="pdp-error" role="alert"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $cartErrors->first('quantity') }}</span></p>@endif
        @guest<p class="pdp-note">No account needed to shop: you check out with an e-mail address.</p>@endguest
    @endif

    {{-- Where to deliver: the one thing a delivery cost depends on that the shopper controls. --}}
    <div class="pdp-region" id="region">
        @if ($d['region'])
            <p class="pdp-region__now"><x-ds.icon name="pin" :size="18" /><span>Delivering to <strong>{{ $d['region']['label'] }}</strong>@if ($d['region']['source'] === 'address') <span class="ds-muted">(your saved address)</span>@endif</span></p>
        @endif
        <details class="pdp-region__change" @if (! $d['region'] || $errors->has('province') || $errors->has('postal_code')) open @endif>
            <summary>{{ $d['region'] ? 'Change where to deliver' : 'Where should it go?' }}</summary>
            <form method="POST" action="{{ route('delivery.region') }}" class="pdp-region__form">
                @csrf
                <input type="hidden" name="return" value="{{ $returnTo }}">
                <x-ds.select name="province" label="Province" id="region-province" placeholder="Choose a province" :options="array_combine($regions, $regions)" :selected="$d['region']['province'] ?? null" required />
                <x-ds.input name="postal_code" id="region-postal" label="Postal code (optional)" inputmode="numeric" autocomplete="postal-code" maxlength="4" :value="$d['region']['postal_code'] ?? ''" />
                <div class="pdp-region__actions">
                    <x-ds.button type="submit" variant="quiet" size="sm">Show delivery</x-ds.button>
                    @if ($d['region'] && $d['region']['source'] === 'chosen' && $user)
                        <button type="submit" name="use" value="address" class="ds-btn ds-btn--link">Use my saved address</button>
                    @endif
                </div>
            </form>
        </details>
    </div>

    <div class="pdp-secondary">
        <x-wish-toggle :product="$product" :variant-id="$variant?->id" :saved="$wished" style="button" />

        @if ($isLive && ! $own)
            @php($forVariant = $hasOptions && $variant ? $variant->id : null)
            @if ($stockState === 'out_of_stock' && $complete)
                @if (! $user)
                    <p class="pdp-alert-note"><a href="{{ route('login') }}">Sign in with InfoDot</a> to be told when this is back. Accounts come from InfoDot.</p>
                @elseif (isset($alerts['back_in_stock']))
                    <form method="POST" action="{{ route('alerts.destroy', $alerts['back_in_stock']) }}" class="pdp-alert-note">@csrf @method('DELETE')
                        <span>We will tell you in the app when it is back.</span> <button type="submit" class="ds-btn ds-btn--link">Cancel the alert</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('alerts.store', $product) }}">@csrf
                        <input type="hidden" name="kind" value="back_in_stock">
                        @if ($forVariant)<input type="hidden" name="variant_id" value="{{ $forVariant }}">@endif
                        <x-ds.button type="submit" variant="quiet" icon="bell">Tell me when it is back</x-ds.button>
                    </form>
                @endif
            @elseif ($complete && $canBuy)
                @if (! $user)
                    <p class="pdp-alert-note"><a href="{{ route('login') }}">Sign in with InfoDot</a> to get an alert if the price drops.</p>
                @elseif (isset($alerts['price_drop']))
                    <form method="POST" action="{{ route('alerts.destroy', $alerts['price_drop']) }}" class="pdp-alert-note">@csrf @method('DELETE')
                        <span>We will tell you if the price falls below {{ \App\Support\Shop::money(\App\Support\Money::toCents($alerts['price_drop']->baseline_price)) }}.</span> <button type="submit" class="ds-btn ds-btn--link">Cancel the alert</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('alerts.store', $product) }}">@csrf
                        <input type="hidden" name="kind" value="price_drop">
                        @if ($forVariant)<input type="hidden" name="variant_id" value="{{ $forVariant }}">@endif
                        <x-ds.button type="submit" variant="quiet" icon="bell">Tell me if the price drops</x-ds.button>
                    </form>
                @endif
            @endif
            @if ($alertErrors->any())<p class="pdp-error" role="alert"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $alertErrors->first() }}</span></p>@endif
        @endif
    </div>

    {{-- How the figure is built, one fact per line (D-192): kept out of the way of the price and the button, but never dropped. --}}
    <details class="pdp-acc" id="price-work">
        <summary>How this price is worked out<x-ds.icon name="chevron-down" :size="18" /></summary>
        <div class="pdp-acc__body">
            <ul class="pdp-work">
                <li>The price is what {{ $store->name }} charges for one item.</li>
                @if ($vatCents !== null)
                    <li>It includes {{ \App\Support\Shop::money($vatCents) }} VAT. {{ $store->name }} is VAT registered.</li>
                @elseif ($vatRegistered === false)
                    <li>{{ $store->name }} is not VAT registered, so no VAT is added.</li>
                @endif
                @if ($reference)
                    <li>Lowest price on Emall from {{ $reference['since']->format('j M') }} to {{ now()->format('j M Y') }}, from our own price record. Not a price the seller typed.</li>
                @endif
                <li>Delivery and the total above are for one item. Quantity, promo codes and other sellers' items are worked out in your cart.</li>
                <li>{{ $store->name }} ships it, not Emall.</li>
            </ul>
        </div>
    </details>

    {{-- Delivery in full: what the seller offers to this place, with estimates labelled as estimates. --}}
    <details class="pdp-acc" id="delivery">
        <summary>Delivery and collection<x-ds.icon name="chevron-down" :size="18" /></summary>
        <div class="pdp-acc__body">
            @if ($d['region'] && $d['options'])
                <ul class="pdp-methods">
                    @foreach ($d['options'] as $o)
                        <li>
                            <span class="pdp-methods__name">{{ $o->name }}</span>
                            <span class="pdp-methods__price">@if ($o->isArranged()) Arranged with the seller @elseif ($o->priceCents === 0) Free @else {{ \App\Support\Shop::money($o->priceCents) }} @endif</span>
                            <span class="pdp-methods__when ds-muted">{{ $o->isArranged() ? 'No date until you and the seller agree one.' : $dateRange($o->estimateLabel) }}</span>
                            @if ($o->isCollection() && $o->collectionAddress)<span class="pdp-methods__when ds-muted">Collect from {{ $o->collectionAddress }}</span>@endif
                            @if ($o->remainingForFree($priceCents) !== null && $o->remainingForFree($priceCents) > 0)<span class="pdp-methods__when ds-muted">Free over {{ \App\Support\Shop::money($o->freeOverCents) }} from this seller.</span>@endif
                        </li>
                    @endforeach
                </ul>
            @elseif ($d['region'] && $d['cannotDeliver'])
                <p>{{ $store->name }} does not deliver to {{ $d['region']['province'] }}. Try another province, or look for a collection option below.</p>
            @elseif ($methods)
                <p class="ds-muted">Choose where it is going to see the cost and an estimated date. How {{ $store->name }} delivers:</p>
            @else
                <p class="ds-muted">{{ $store->name }} has not set delivery methods. Delivery is arranged with the seller after you order, and Emall adds no delivery charge.</p>
            @endif
            @if (! ($d['region'] && $d['options']) && $methods)
                <ul class="pdp-methods">
                    @foreach ($methods as $m)
                        <li><span class="pdp-methods__name">{{ $m['name'] }}</span><span class="pdp-methods__price">{{ $m['price'] }}</span><span class="pdp-methods__when ds-muted">{{ $m['window'] }}</span></li>
                    @endforeach
                </ul>
            @endif
            <p class="ds-muted pdp-note">Dates are estimates from the seller's handling time and delivery method, counted in working days. They are not promises.</p>
        </div>
    </details>

    @if ($buyable)
        <div class="ds-buybar pdp-bar">
            <x-ds.price :cents="$priceCents" size="lg" />
            <x-ds.button type="submit" form="buy-form" icon="cart">Add to cart</x-ds.button>
        </div>
    @elseif ($canBuy && $hasOptions)
        <div class="ds-buybar pdp-bar">
            <x-ds.price :cents="$priceCents" size="lg" />
            <x-ds.button href="#options" icon="sliders">Choose options</x-ds.button>
        </div>
    @endif
</div>
