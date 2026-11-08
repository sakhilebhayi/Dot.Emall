@php
    use App\Support\Shop;
    $val = fn (string $key, $default = '') => old($key, $draft[$key] ?? $default);
    $typed = old('address', $draft['address'] ?? []);
    $typed = is_array($typed) ? $typed : [];
    if ($typed === [] && $user) { $typed = ['recipient_name' => $user->name]; }
    $useNew = (string) old('address_id', $selectedAddress) === 'new' || $addresses->isEmpty();
    $changed = session('price_changed');
    $multi = $parcelCount > 1;
    $payDisabled = $gatewayClosed || ! $quote->canCheckout() || ! $deliveryDone;
@endphp
<x-store-layout>
    <x-slot:title>Checkout</x-slot:title>
    <x-slot:description>Enter where to deliver, see every price, and pay securely. No account needed.</x-slot:description>
    <x-slot:heading>Checkout</x-slot:heading>
    <x-slot:lead>Nothing is charged until you press the pay button at the end. The total you see is the total you pay.</x-slot:lead>

    <x-ds.progress-steps :steps="$steps" :current="$current" class="co-steps" />

    @if ($gatewayClosed)
        <x-ds.alert tone="crit" title="Payments are not open yet" class="co-gap">Orders cannot be placed right now, and nothing has been charged. Your cart is kept. Please try again later.</x-ds.alert>
    @elseif (! $paystackUsable)
        <x-ds.alert tone="info" class="co-gap">Card payment is not switched on in this environment. An order you place now is saved as pending and unpaid, and nothing is charged.</x-ds.alert>
    @endif

    @if ($changed)
        <x-ds.alert tone="cau" title="The total changed" class="co-gap">It was {{ Shop::money($changed['from']) }} and is now {{ Shop::money($changed['to']) }}. Nothing was charged. Check the figures below, then press the pay button again to confirm the new total.</x-ds.alert>
    @elseif (session('error'))
        <x-ds.alert tone="crit" class="co-gap">{{ session('error') }}</x-ds.alert>
    @endif

    @if ($errors->any())
        <x-ds.alert tone="crit" title="Please check these before going on" class="co-gap" id="form-errors">
            <ul class="co-list">@foreach (array_unique($errors->all()) as $message)<li>{{ $message }}</li>@endforeach</ul>
        </x-ds.alert>
    @endif

    @if ($multi)
        <p class="co-multi"><x-ds.icon name="package-check" :size="20" /><span>Your order ships from {{ $parcelCount }} sellers. Each one packs their own parcel, charges their own delivery and sets their own arrival estimate, so the parcels may arrive on different days.</span></p>
    @endif

    <form method="POST" action="{{ route('checkout.place') }}" class="co-layout" data-checkout-form novalidate>
        @csrf
        <input type="hidden" name="checkout_token" value="{{ $token }}">
        <input type="hidden" name="expected_total_cents" value="{{ $quote->totalCents }}">

        <div class="co-main">
            {{-- 1 Contact --}}
            <section class="co-step" id="contact" aria-labelledby="h-contact" data-state="{{ $contactDone ? 'done' : 'current' }}">
                <h2 id="h-contact"><span class="co-step__n">1</span> Contact</h2>
                @if ($signedIn)
                    <p>Signed in as <strong>{{ $user->name }}</strong>. Order updates go to <strong>{{ $user->email }}</strong>.</p>
                @else
                    <x-ds.input label="E-mail address" name="email" id="co-email" type="email" :value="$val('email')" required autocomplete="email" hint="We send your order confirmation and the link to follow your order here. You do not need an account." />
                    <p class="ds-muted co-note">Prefer to keep your orders in one place? <a href="{{ route('login') }}">Sign in with InfoDot</a> first. Accounts come from InfoDot.</p>
                @endif
                <x-ds.input label="Mobile number (optional)" name="phone" id="co-phone" type="tel" :value="$val('phone', $phone)" autocomplete="tel" hint="Only given to a seller or courier for the parcel they are sending." />
            </section>

            {{-- 2 Delivery --}}
            <section class="co-step" id="delivery" aria-labelledby="h-delivery" data-state="{{ $deliveryDone ? 'done' : ($contactDone ? 'current' : 'todo') }}">
                <h2 id="h-delivery"><span class="co-step__n">2</span> Delivery</h2>

                @if ($signedIn && $addresses->isNotEmpty())
                    <fieldset class="ds-fieldset">
                        <legend>Where should it go?</legend>
                        @foreach ($addresses as $a)
                            <label class="ds-choice co-option">
                                <input type="radio" name="address_id" value="{{ $a->id }}" @checked(! $useNew && (int) old('address_id', $selectedAddress) === $a->id)>
                                <span class="ds-choice__text">
                                    <span class="ds-choice__label">{{ $a->label ?: $a->recipient_name }}@if ($a->is_default_shipping) <x-ds.badge tone="info">Default</x-ds.badge>@endif</span>
                                    <span class="ds-choice__hint">{{ $a->recipient_name }}, {{ \App\Models\Address::oneLine($a->snapshot()) }}</span>
                                </span>
                            </label>
                        @endforeach
                        <label class="ds-choice co-option">
                            <input type="radio" name="address_id" value="new" @checked($useNew)>
                            <span class="ds-choice__text"><span class="ds-choice__label">Use a different address</span><span class="ds-choice__hint">Fill in the form below. You can save it to your address book.</span></span>
                        </label>
                    </fieldset>
                    <p class="ds-muted co-note"><a href="{{ route('account.addresses') }}">Manage your address book</a></p>
                    <details class="co-newaddr" {{ $useNew || collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'address.')) ? 'open' : '' }}>
                        <summary>Enter an address</summary>
                        @include('checkout._address-fields', ['values' => $typed, 'provinces' => $provinces, 'withPhone' => false])
                        <x-ds.checkbox label="Save this address to my address book" name="save_address" value="1" :checked="(bool) old('save_address', $draft['save_address'] ?? false)" />
                    </details>
                @else
                    @include('checkout._address-fields', ['values' => $typed, 'provinces' => $provinces, 'withPhone' => false])
                    @if ($signedIn)<x-ds.checkbox label="Save this address to my address book" name="save_address" value="1" :checked="(bool) old('save_address', $draft['save_address'] ?? true)" />@endif
                @endif

                <div class="co-stepact">
                    <x-ds.button type="submit" variant="{{ $deliveryDone ? 'quiet' : 'primary' }}" formaction="{{ route('checkout.delivery') }}" formnovalidate icon="truck">{{ $deliveryDone ? 'Update delivery options' : 'Show delivery options' }}</x-ds.button>
                </div>

                @if ($contactDone && $region)
                    <div class="co-parcels" aria-live="polite">
                        @foreach ($quote->groups as $storeId => $g)
                            <fieldset class="ds-fieldset co-parcelopt">
                                <legend>Parcel {{ $loop->iteration }}: from {{ $g['store_name'] }}</legend>
                                @if (! $g['can_deliver'])
                                    <p class="co-fix" role="alert">{{ $g['store_name'] }} does not deliver to {{ $region->province ?? 'that address' }}. Change the address, or go back to the cart and remove their items.</p>
                                @else
                                    @foreach ($g['options'] as $o)
                                        <label class="ds-choice co-option">
                                            <input type="radio" name="shipping_methods[{{ $storeId }}]" value="{{ $o->methodId }}" @checked($g['selected'] && $g['selected']->methodId === $o->methodId)>
                                            <span class="ds-choice__text">
                                                <span class="ds-choice__label">{{ $o->name }}<span class="co-option__price">{{ $o->priceCents === 0 ? 'Free' : Shop::money($o->priceCents) }}</span></span>
                                                <span class="ds-choice__hint">{{ $o->estimateLabel }}</span>
                                                @if ($o->isCollection() && $o->collectionAddress)<span class="ds-choice__hint">Collect from {{ $o->collectionAddress }}</span>@endif
                                                @if ($o->instructions)<span class="ds-choice__hint">{{ $o->instructions }}</span>@endif
                                            </span>
                                        </label>
                                    @endforeach
                                @endif
                            </fieldset>
                        @endforeach
                        <p class="ds-muted co-note">Delivery dates are estimates from each seller's handling time and delivery method. They are not promises. Choose a different option, then press Update delivery options to see the new total.</p>
                    </div>
                @elseif ($contactDone)
                    <p class="ds-muted co-note">Delivery options appear once you enter where to deliver.</p>
                @endif
            </section>

            {{-- 3 Payment --}}
            <section class="co-step" id="payment" aria-labelledby="h-payment" data-state="{{ $deliveryDone ? 'done' : 'todo' }}">
                <h2 id="h-payment"><span class="co-step__n">3</span> Payment</h2>
                @if (! $deliveryDone)
                    <p class="ds-muted">Available once delivery is set.</p>
                @else
                    <p><x-ds.icon name="lock" :size="18" /> You pay on Paystack's secure page, in {{ $currency }}. Paystack shows the payment methods it offers you there. Dot.Emall never sees your card details.
                        @if ($testMode)<x-ds.badge tone="cau">Test mode: no real money moves</x-ds.badge>@endif</p>
                    <p class="ds-muted">Pressing the pay button places the order and holds your items for {{ $holdMinutes }} minutes while you pay. If it is not paid by then, the order is cancelled and the items go back on sale. The exact time is shown on your order.</p>
                @endif
            </section>

            {{-- 4 Review --}}
            <section class="co-step" id="review" aria-labelledby="h-review" data-state="{{ $deliveryDone ? 'current' : 'todo' }}">
                <h2 id="h-review"><span class="co-step__n">4</span> Review and pay</h2>
                @if (! $deliveryDone)
                    <p class="ds-muted">Available once delivery is set. You will see every line, every fee and the total here first.</p>
                @else
                    @foreach ($quote->groups as $storeId => $g)
                        <div class="co-review">
                            <h3>From {{ $g['store_name'] }}</h3>
                            @foreach ($g['lines'] as $line)
                                <x-ds.cart-line :title="$line->product->name" :href="route('products.show', $line->product)" :image="$line->product->images->first()?->url()" :option="$line->variant->label()" :unit-cents="$line->unitCents()" :quantity="$line->quantity" :line-cents="$line->totalCents()" />
                            @endforeach
                            <dl class="co-parcel__sum">
                                <div><dt>Delivery, {{ $g['selected']?->name }}</dt><dd>{{ $g['delivery_cents'] === 0 ? 'Free' : Shop::money($g['delivery_cents']) }}</dd></div>
                                @if ($g['selected'])<div><dt class="ds-muted">Arrival</dt><dd class="ds-muted">{{ $g['selected']->estimateLabel }}</dd></div>@endif
                            </dl>
                        </div>
                    @endforeach

                    <div class="co-terms">
                        <h3>Returns and your rights</h3>
                        <p>You can ask for a return within {{ $returnDays }} days of delivery. If an item is faulty, not as described or the wrong one, the seller pays for the return. If you changed your mind, you pay for it. Refunds go back through Paystack once the seller has the item. We apply the {{ $returnDays }}-day period that South African law (ECTA section 44) sets for online sales to every order, as our standard.</p>
                        <p class="ds-muted co-note">The box to accept these is beside the pay button. <a href="{{ route('terms.show') }}">Terms of service</a>, <a href="{{ route('policy.show') }}">privacy policy</a> and <a href="{{ route('help.returns') }}">how returns work</a>.</p>
                    </div>
                @endif
            </section>
        </div>

        <aside class="co-side" aria-label="Order total">
            @include('checkout._label', ['quote' => $quote, 'heading' => 'Total to pay', 'behind' => 'var(--ground)'])
            <div class="co-promo">
                <x-ds.input label="Promo code (optional)" name="promo_code" id="co-promo" :value="$val('promo_code')" autocomplete="off" hint="Press Show or Update delivery options to check a code before you pay." />
            </div>
            <div class="co-act">
                @if ($gatewayClosed)
                    <x-ds.button variant="primary" size="lg" block disabled>Payments are not open yet</x-ds.button>
                @elseif ($payDisabled)
                    <x-ds.button variant="primary" size="lg" block disabled>Pay {{ Shop::money($quote->totalCents) }}</x-ds.button>
                    <p class="ds-muted co-note">{{ ! $deliveryDone ? 'Set your delivery details first.' : 'Something in your cart needs fixing first.' }}</p>
                @else
                    <x-ds.checkbox label="I have read and accept the terms and the returns summary" name="terms" value="1" :checked="(bool) old('terms')" />
                    <x-ds.button type="submit" variant="primary" size="lg" block icon="lock" data-pay>{{ $paystackUsable ? 'Pay '.Shop::money($quote->totalCents).' securely' : 'Place order for '.Shop::money($quote->totalCents) }}</x-ds.button>
                    <p class="ds-muted co-note" data-pay-note>By paying you place an order for {{ Shop::money($quote->totalCents) }}. {{ $paystackUsable ? 'Press once: the button locks while we take you to Paystack.' : 'No payment is taken.' }}</p>
                @endif
                <x-ds.button :href="route('cart.index')" variant="link" icon="arrow-left">Back to the cart</x-ds.button>
            </div>
        </aside>
    </form>
</x-store-layout>
