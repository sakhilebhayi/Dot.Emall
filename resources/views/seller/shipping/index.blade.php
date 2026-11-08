@php use App\Support\Shop; @endphp
<x-seller-layout>
    <x-slot:title>Shipping</x-slot:title>
    <x-slot:heading>Shipping</x-slot:heading>
    <x-slot:lead>How you send things, what you charge and when buyers can expect it.</x-slot:lead>

    <div class="sd-page">
        <x-seller.errors :only="['status']" />
        @if ($methods->isEmpty())
            <x-ds.alert tone="cau" title="No shipping method yet">@if ($requireMethods)Shoppers cannot buy from you until you add one. @else Until you add one, shoppers are told delivery is arranged with you and pay nothing extra. @endif A store also needs a method before the Emall team will review it.</x-ds.alert>
        @endif

        <div class="sd-cols">
            <div class="sd-stack">
                <section aria-labelledby="sh-methods" class="sd-stack">
                    <h2 id="sh-methods" class="sd-h2">Your methods</h2>
                    @foreach ($methods as $m)
                        @php $failed = $errors->any() && (string) old('_method_id') === (string) $m->id; @endphp
                        <details class="sd-do" @if ($failed) open @endif id="method-{{ $m->id }}">
                            @php
                                $price = match ($m->type) {
                                    \App\Enums\ShippingMethodType::Collection => 'free, collected from you',
                                    \App\Enums\ShippingMethodType::FreeOver => Shop::amount($m->price).', free over '.Shop::amount($m->free_over),
                                    default => Shop::amount($m->price),
                                };
                                $summary = $m->type->label().': '.$price.'. Packing '.$m->handling_days.' '.($m->handling_days === 1 ? 'day' : 'days')
                                    .($m->type === \App\Enums\ShippingMethodType::Collection ? '' : ', delivery '.$m->window_min_days.' to '.$m->window_max_days.' days').'. '
                                    .($m->regions->isNotEmpty() ? 'Only in '.$m->regions->count().' '.($m->regions->count() === 1 ? 'region' : 'regions').'.' : 'To everywhere you can reach.');
                            @endphp
                            <summary class="sd-summary">
                                <span><strong>{{ $m->name }}</strong> @unless ($m->is_active)<x-ds.badge tone="cau" icon="eye-off">Not offered</x-ds.badge>@endunless</span>
                                <span class="sd-muted">{{ $summary }}</span>
                                <span class="sd-summary__edit">Edit<x-ds.icon name="chevron-down" :size="16" /></span>
                            </summary>
                            <form method="POST" action="{{ route('seller.shipping.update', $m) }}" class="sd-stack" novalidate>
                                @csrf @method('PATCH')
                                @include('seller.shipping._form', ['method' => $m, 'types' => $types, 'prefix' => 'm'.$m->id])
                                <div class="sd-actions">
                                    <x-ds.button type="submit" variant="primary">Save method</x-ds.button>
                                    <button type="button" class="ds-btn ds-btn--danger" data-dialog-open="rm-method-{{ $m->id }}">Delete</button>
                                </div>
                            </form>

                            <div class="sd-stack">
                                <h3 class="sd-h3">Where it applies</h3>
                                <p class="sd-muted sd-measure">With no rules the method is offered to every address. Add a rule to limit it to a province or a range of postal codes, and optionally charge a different price there. The most specific rule that matches an address wins.</p>
                                @if ($m->regions->isNotEmpty())
                                    <ul class="sd-lines">
                                        @foreach ($m->regions as $r)
                                            <li><span class="sd-thumb" style="width: 2rem; height: 2rem;"><x-ds.icon name="pin" :size="18" /></span>
                                                <span>{{ $r->province ?: 'Any province' }}@if ($r->postal_from), postal codes {{ $r->postal_from }} to {{ $r->postal_to }}@endif <span class="sd-muted">{{ $r->price !== null ? 'costs '.Shop::amount($r->price) : 'uses the method price' }}</span></span>
                                                <form method="POST" action="{{ route('seller.shipping.regions.destroy', [$m, $r]) }}">@csrf @method('DELETE')<button class="ds-btn ds-btn--link" type="submit">Remove<span class="sd-sr"> rule {{ $r->province ?: 'postal range' }}</span></button></form>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <form method="POST" action="{{ route('seller.shipping.regions.store', $m) }}" class="sd-stack" novalidate>
                                    @csrf
                                    <input type="hidden" name="_method_id" value="{{ $m->id }}">
                                    <div class="sd-fields sd-fields--three">
                                        <x-ds.select label="Province" name="province" :id="'rp'.$m->id" :options="array_combine($provinces, $provinces)" placeholder="Any province" :selected="$failed ? old('province') : null" :error="$failed ? $errors->first('province') : null" />
                                        <x-ds.input label="Postal codes from" name="postal_from" :id="'rf'.$m->id" inputmode="numeric" maxlength="4" :value="$failed ? old('postal_from') : ''" :error="$failed ? $errors->first('postal_from') : null" />
                                        <x-ds.input label="to" name="postal_to" :id="'rt'.$m->id" inputmode="numeric" maxlength="4" :value="$failed ? old('postal_to') : ''" :error="$failed ? $errors->first('postal_to') : null" />
                                        <x-ds.input label="Price here (rand), optional" name="price" :id="'rpr'.$m->id" inputmode="decimal" :value="$failed ? old('price') : ''" :error="$failed ? $errors->first('price') : null" autocomplete="off" />
                                    </div>
                                    <div><x-ds.button type="submit" variant="quiet" icon="plus">Add rule</x-ds.button></div>
                                </form>
                            </div>
                            <x-ds.confirm-dialog id="rm-method-{{ $m->id }}" title="Delete {{ $m->name }}?" :action="route('seller.shipping.destroy', $m)" method="DELETE" confirmLabel="Delete method">Buyers will no longer be offered it. Orders already placed keep the price and dates the buyer was shown.</x-ds.confirm-dialog>
                        </details>
                    @endforeach
                </section>

                <section aria-labelledby="sh-add" class="sd-stack">
                    <h2 id="sh-add" class="sd-h2">Add a method</h2>
                    <form method="POST" action="{{ route('seller.shipping.store') }}" class="sd-stack" novalidate>
                        @csrf
                        @include('seller.shipping._form', ['method' => null, 'types' => $types, 'prefix' => 'new'])
                        <div><x-ds.button type="submit" variant="primary" icon="plus">Add shipping method</x-ds.button></div>
                    </form>
                </section>
            </div>

            <aside class="sd-stack" aria-labelledby="sh-preview">
                <h2 id="sh-preview" class="sd-h2">What a buyer sees</h2>
                <p class="sd-muted">This uses the same calculation as the cart, with your saved methods. Change a method, save it, and try again.</p>
                <form method="GET" action="{{ route('seller.shipping') }}" class="sd-stack" novalidate>
                    <x-ds.select label="Buyer's province" name="province" id="pv-province" :options="array_combine($provinces, $provinces)" :selected="$previewProvince" />
                    <x-ds.input label="Postal code (optional)" name="postal_code" id="pv-postal" inputmode="numeric" maxlength="4" :value="$previewPostal" />
                    <x-ds.input label="Items total with you (rand)" name="basket" id="pv-basket" inputmode="decimal" :value="$previewBasket" autocomplete="off" />
                    <div><x-ds.button type="submit" variant="quiet">Show me</x-ds.button></div>
                </form>
                @if ($quote)
                    <div class="sd-do" aria-live="polite">
                        <h3>A buyer in {{ $previewProvince }}{{ $previewPostal ? ' ('.$previewPostal.')' : '' }} with {{ Shop::amount($previewBasket) }} of your items</h3>
                        @if (! $quote->canDeliver())
                            <p class="sd-muted">Nothing is offered to this address. {{ $methods->isEmpty() ? '' : 'Check your region rules.' }}</p>
                        @else
                            <ul class="sd-quotes">
                                @foreach ($quote->options as $o)
                                    <li>
                                        <strong>{{ $o->name }}</strong>
                                        <span class="sd-quotes__price">{{ $o->isArranged() ? 'Arranged with you' : ($o->priceCents === 0 ? 'Free' : Shop::money($o->priceCents)) }}</span>
                                        <span class="sd-muted sd-quotes__est">{{ $o->estimateLabel }}@if ($rem = $o->remainingForFree((int) round((float) $previewBasket * 100))) Spend {{ Shop::money($rem) }} more for free delivery.@endif</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </aside>
        </div>
    </div>
</x-seller-layout>
