@php
    use App\Support\Shop;
    $count = $lines->sum(fn ($l) => $l->quantity);
    $blocked = $quote->problems !== [];
    $undeliverable = collect($quote->groups)->contains(fn ($g) => ! $g['can_deliver']);
    $promo = $quote->promo;
@endphp
<x-store-layout>
    <x-slot:title>Your cart</x-slot:title>
    <x-slot:description>Your items grouped by seller, with delivery prices and the total to pay before you check out.</x-slot:description>
    <x-slot:heading>Your cart</x-slot:heading>
    @if ($lines->isNotEmpty())
        <x-slot:lead>{{ $count }} {{ $count === 1 ? 'item' : 'items' }} from {{ $parcelCount }} {{ $parcelCount === 1 ? 'seller' : 'sellers' }}. @if ($parcelCount > 1)Each seller sends their own parcel and sets their own delivery price.@endif</x-slot:lead>
    @endif

    @if ($promoNotice)<x-ds.alert tone="cau" class="co-gap">{{ $promoNotice }}</x-ds.alert>@endif

    @if ($changes !== [])
        <x-ds.alert tone="cau" title="Some prices changed since you last looked" class="co-gap">
            <ul class="co-list">
                @foreach ($changes as $c)
                    <li>{{ $c['name'] }}: was {{ Shop::money($c['from']) }}, now {{ Shop::money($c['to']) }} each.</li>
                @endforeach
            </ul>
            <p>The total below uses the new prices. Keep them to go on, or remove an item you no longer want.</p>
            <form method="POST" action="{{ route('cart.prices.accept') }}">@csrf<x-ds.button type="submit" variant="quiet" size="sm">Keep the new prices</x-ds.button></form>
        </x-ds.alert>
    @endif

    @if ($lines->isEmpty())
        <div class="co-empty">
            <x-ds.empty-state title="Your cart is empty" icon="cart">
                Add something from a store and it will wait here. You do not need an account to buy.
                <x-slot:actions>
                    <x-ds.button :href="route('products.index')" icon="search">Continue shopping</x-ds.button>
                    @unless ($signedIn)<x-ds.button :href="route('login')" variant="quiet">Sign in with InfoDot</x-ds.button>@endunless
                </x-slot:actions>
            </x-ds.empty-state>
        </div>

        @if ($suggestions->isNotEmpty())
            <x-ds.section :title="$suggestions['title']" :lead="$suggestions['basis']">
                <div class="ds-grid">
                    @foreach ($suggestions['products'] as $p)
                        <x-ds.product-card :href="route('products.show', $p)" :title="$p->name" :image="$p->images->first()?->cardUrl()" :store="$p->store->name" :amount="$p->price" :stock="$p->stock" ratio="1 / 1" />
                    @endforeach
                </div>
            </x-ds.section>
        @endif
    @else
        <div class="co-layout">
            <div class="co-main">
                @foreach ($quote->groups as $storeId => $g)
                    <section class="co-parcel" aria-labelledby="parcel-{{ $storeId }}">
                        <header class="co-parcel__head">
                            <h2 id="parcel-{{ $storeId }}">Sold and sent by {{ $g['store_name'] }}</h2>
                            <p class="ds-muted">Parcel {{ $loop->iteration }} of {{ $parcelCount }}</p>
                        </header>

                        @foreach ($g['lines'] as $line)
                            @php
                                $variantId = $line->variant->id;
                                $problem = $quote->problems[$variantId] ?? null;
                                $stock = (int) $line->variant->stock;
                                $max = max(1, min(99, $stock));
                                $note = $problem ?? ($stock > 0 && $stock <= 5 && ! $problem ? 'Only '.$stock.' in stock.' : null);
                                $qError = session('quantity_variant') === $variantId ? $errors->first('quantity') : null;
                            @endphp
                            <x-ds.cart-line
                                :title="$line->product->name"
                                :href="$line->product->trashed() ? null : route('products.show', $line->product)"
                                :image="$line->product->images->first()?->cardUrl()"
                                :option="$line->variant->label()"
                                :unit-cents="$line->unitCents()"
                                :quantity="$line->quantity"
                                :line-cents="$line->totalCents()"
                                :note="$note">
                                @if ($problem)
                                    <p class="co-fix">@if ($stock > 0 && $line->quantity > $stock)Lower the quantity to {{ $stock }} to keep it, or remove it.@else Remove it to go on, or save it to your wishlist for later.@endif</p>
                                @endif
                                <div class="co-line-actions">
                                    <form method="POST" action="{{ route('cart.update', $variantId) }}" class="co-qty" data-autosubmit-form>
                                        @csrf @method('PATCH')
                                        <x-ds.quantity-stepper :value="$line->quantity" :max="$max" :id="'qty-'.$variantId" :label="'Quantity of '.$line->product->name" />
                                        <noscript><x-ds.button type="submit" variant="quiet" size="sm">Update</x-ds.button></noscript>
                                    </form>
                                    <form method="POST" action="{{ route('cart.destroy', $variantId) }}">@csrf @method('DELETE')
                                        <x-ds.button type="submit" variant="link" size="sm" icon="trash">Remove<span class="ds-visually-hidden"> {{ $line->product->name }}</span></x-ds.button>
                                    </form>
                                    @if ($signedIn)
                                        <form method="POST" action="{{ route('cart.wishlist', $variantId) }}">@csrf
                                            <x-ds.button type="submit" variant="link" size="sm" icon="heart">Move to wishlist<span class="ds-visually-hidden">: {{ $line->product->name }}</span></x-ds.button>
                                        </form>
                                    @else
                                        <a class="co-signin-link" href="{{ route('login') }}">Sign in with InfoDot to save for later</a>
                                    @endif
                                </div>
                                @if ($qError)<p class="ds-field__error" role="alert"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $qError }}</span></p>@endif
                            </x-ds.cart-line>
                        @endforeach

                        <div class="co-delivery">
                            <h3>Delivery from {{ $g['store_name'] }}</h3>
                            @if (! $g['can_deliver'])
                                <p class="co-fix">{{ $region ? $g['store_name'].' does not deliver to '.($region->province ?? 'that address').'.' : 'Choose where to deliver below to see this seller\'s options.' }} @if ($region)Remove these items or change the delivery place.@endif</p>
                            @else
                                <form method="POST" action="{{ route('cart.delivery') }}" data-autosubmit-form>
                                    @csrf
                                    <fieldset class="ds-fieldset">
                                        <legend class="ds-visually-hidden">Delivery method for {{ $g['store_name'] }}</legend>
                                        @foreach ($g['options'] as $o)
                                            <label class="ds-choice co-option">
                                                <input type="radio" name="shipping_methods[{{ $storeId }}]" value="{{ $o->methodId }}" @checked($g['selected'] && $g['selected']->methodId === $o->methodId)>
                                                <span class="ds-choice__text">
                                                    <span class="ds-choice__label">{{ $o->name }}<span class="co-option__price">{{ $o->priceCents === 0 ? 'Free' : Shop::money($o->priceCents) }}</span></span>
                                                    <span class="ds-choice__hint">{{ $o->estimateLabel }}</span>
                                                    @if ($o->isCollection() && $o->collectionAddress)<span class="ds-choice__hint">Collect from {{ $o->collectionAddress }}</span>@endif
                                                    @if ($o->remainingForFree($g['items_cents']) !== null && $o->remainingForFree($g['items_cents']) > 0)<span class="ds-choice__hint">Spend {{ Shop::money($o->remainingForFree($g['items_cents'])) }} more with this seller for free delivery.</span>@endif
                                                </span>
                                            </label>
                                        @endforeach
                                    </fieldset>
                                    <noscript><x-ds.button type="submit" variant="quiet" size="sm">Update delivery</x-ds.button></noscript>
                                </form>
                            @endif
                        </div>

                        <dl class="co-parcel__sum">
                            <div><dt>Items</dt><dd>{{ Shop::money($g['items_cents']) }}</dd></div>
                            @if ($g['discount_cents'] > 0)<div><dt>Discount</dt><dd>{{ Shop::money(-$g['discount_cents']) }}</dd></div>@endif
                            @if ($g['can_deliver'])<div><dt>Delivery</dt><dd>{{ $g['delivery_cents'] === 0 ? 'Free' : Shop::money($g['delivery_cents']) }}</dd></div>@endif
                            <div class="co-parcel__total"><dt>Parcel total</dt><dd>{{ Shop::money($g['total_cents']) }}</dd></div>
                        </dl>
                    </section>
                @endforeach
            </div>

            <aside class="co-side" aria-label="Order total">
                @include('checkout._label', ['quote' => $quote, 'heading' => 'Total to pay', 'behind' => 'var(--ground)'])

                <div class="co-act">
                    @if ($blocked)
                        <p class="co-fix" role="alert">Fix the items marked above before you check out.</p>
                    @elseif ($undeliverable)
                        <p class="co-fix" role="alert">One seller cannot deliver to the place below yet. Change the place or remove their items.</p>
                    @endif
                    @if ($blocked || $undeliverable)
                        <x-ds.button variant="primary" size="lg" block disabled>Go to checkout</x-ds.button>
                    @else
                        <x-ds.button :href="route('checkout.show')" variant="primary" size="lg" block iconRight="arrow-right">Go to checkout</x-ds.button>
                    @endif
                    <p class="ds-muted co-note">You pay on the next steps. {{ $signedIn ? '' : 'No account needed: we send your order link to your e-mail.' }}</p>
                    <x-ds.button :href="route('products.index')" variant="link">Continue shopping</x-ds.button>
                </div>

                <x-ds.panel title="Promo code" class="co-panel">
                    @if ($promo)
                        <p><span class="ds-code">{{ $promo->code }}</span> is applied. {{ \App\Services\Checkout\CartPage::describePromo($quote) }}.@if (filled($promo->conditions)) The seller's terms: {{ $promo->conditions }}@if (! str_ends_with(trim((string) $promo->conditions), '.')).@endif @endif @if ($promo->expires_at) Valid until {{ $promo->expires_at->format('j M Y') }}.@endif @if ($promo->max_uses !== null) Limited to {{ $promo->max_uses }} uses in total, {{ max(0, $promo->max_uses - $promo->used_count) }} left.@endif</p>
                        <form method="POST" action="{{ route('cart.promo.clear') }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="quiet" size="sm">Remove code</x-ds.button></form>
                    @else
                        <form method="POST" action="{{ route('cart.promo') }}" class="co-inline">
                            @csrf
                            <x-ds.input label="Have a code?" name="promo_code" id="cart-promo" autocomplete="off" hint="A code works on items from the seller who issued it. The terms are shown once it applies." />
                            <x-ds.button type="submit" variant="quiet">Apply</x-ds.button>
                        </form>
                    @endif
                </x-ds.panel>

                <x-ds.panel title="Delivering to" class="co-panel">
                    <p class="ds-muted">@if ($region){{ $region->province ?? 'South Africa' }}{{ $region->postalCode ? ', '.$region->postalCode : '' }}. Delivery prices and dates are estimates for this place.@else Not set. Prices that depend on the place show once you choose it.@endif</p>
                    <form method="POST" action="{{ route('cart.region') }}" class="co-region">
                        @csrf
                        <x-ds.select label="Province" name="province" id="cart-province" :options="array_combine($provinces, $provinces)" placeholder="Choose a province" :selected="$estimate['province'] ?? $region?->province" />
                        <x-ds.input label="Postal code" name="postal_code" id="cart-postal" inputmode="numeric" maxlength="4" autocomplete="postal-code" :value="$estimate['postal_code'] ?? $region?->postalCode" />
                        <x-ds.button type="submit" variant="quiet">Update delivery prices</x-ds.button>
                    </form>
                </x-ds.panel>
            </aside>
        </div>
    @endif
</x-store-layout>
