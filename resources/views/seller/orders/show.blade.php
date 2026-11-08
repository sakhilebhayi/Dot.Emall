@php
    use App\Enums\OrderStatus as S;
    use App\Support\Shop;
    $by = fn ($cents) => $order->money($cents);
    $toPack = $items->filter(fn ($l) => $l->status === S::Pending);
    $shippable = $items->filter(fn ($l) => in_array($l->status, [S::Pending, S::Processing], true));
    $out = $items->filter(fn ($l) => in_array($l->status, [S::Shipped, S::ReadyForCollection], true));
    $cancellable = $items->filter(fn ($l) => $l->status->isCancellable());
    $collection = $group->isCollection();
    $derived = S::derive($items->pluck('status'));
    $net = $items->reject->isCancelled()->sum(fn ($l) => $l->netCents());
@endphp
<x-seller-layout>
    <x-slot:title>Order {{ $order->reference() }}</x-slot:title>
    <x-slot:heading>Order {{ $order->reference() }}</x-slot:heading>
    <x-slot:lead>Placed {{ $order->created_at->format('j M Y') }}. This is your parcel only: other sellers' items on the same order are not shown.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Orders', 'href' => route('seller.orders.index')], ['label' => 'Order '.$order->reference()]]" /></x-slot:breadcrumbs>
    <x-slot:actions><x-ds.button variant="quiet" :href="route('seller.orders.slip', $group)" target="_blank" rel="noopener" icon="receipt">Packing slip</x-ds.button></x-slot:actions>

    <div class="sd-page">
        <x-seller.errors />

        <div class="sd-status-line">
            @if (! $paid)<x-ds.status state="awaiting_payment" />@else<x-ds.status :state="$derived->value" />@endif
            <span class="sd-muted">{{ $paid ? 'Paid' : 'Not paid yet' }}@if ($order->paid_at), {{ $order->paid_at->format('j M Y') }}@endif</span>
            @if ($shipBy && $shippable->isNotEmpty() && $paid)<span @if ($shipBy->copy()->endOfDay()->isPast()) style="color: var(--crit); font-weight: 650;" @endif>Ship by {{ $shipBy->format('D j M') }}</span>@endif
        </div>

        @unless ($paid)
            <x-ds.alert tone="cau" title="Do not ship this yet">The buyer has not paid. Emall will not let you pack or ship it until the payment is confirmed, and you will be told when it is.</x-ds.alert>
        @endunless

        <div class="sd-cols">
            <div class="sd-stack">
                <section aria-labelledby="o-items" class="sd-stack">
                    <h2 id="o-items" class="sd-h2">Items</h2>
                    <ul class="sd-lines">
                        @foreach ($items as $l)
                            <li>
                                <x-ds.status :state="$l->status->value" />
                                <span><strong>{{ $l->displayName() }}</strong> x{{ $l->quantity }}@if ($l->sku) <span class="sd-code">{{ $l->sku }}</span>@endif
                                    @foreach ($l->returnRequests as $rr)<br><a href="{{ route('seller.returns.show', $rr) }}">Return {{ $rr->status->value }}</a>@endforeach</span>
                                <span class="sd-line__total">{{ $by($l->netCents()) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="sd-quiet">Your items after any promo code. Delivery fee for this parcel: {{ $by($group->delivery_cents) }}.</p>
                </section>

                @if ($paid && $shippable->isNotEmpty())
                    <section aria-labelledby="o-do" class="sd-stack">
                        <h2 id="o-do" class="sd-h2">{{ $collection ? 'Get it ready' : 'Pack and ship' }}</h2>
                        @if ($toPack->isNotEmpty())
                            <form method="POST" action="{{ route('seller.orders.pack', $group) }}">@csrf<x-ds.button type="submit" variant="quiet" icon="box">Mark as packing</x-ds.button></form>
                        @endif
                        @if ($collection)
                            <div class="sd-do">
                                <h3>Ready for collection</h3>
                                <p class="sd-muted">The buyer is told it is ready and where to collect it: {{ $group->method_name }}.</p>
                                <form method="POST" action="{{ route('seller.orders.ready', $group) }}">@csrf<x-ds.button type="submit" variant="primary" icon="check">Mark ready for collection</x-ds.button></form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('seller.orders.ship', $group) }}" class="sd-do" novalidate>
                                @csrf
                                <h3>Ship it</h3>
                                @if ($shippable->count() > 1)
                                    <fieldset class="ds-fieldset"><legend>Which items are in this shipment</legend>
                                        @foreach ($shippable as $l)<label class="ds-choice"><input type="checkbox" name="order_item_ids[]" value="{{ $l->id }}" @checked(old('order_item_ids') ? in_array($l->id, (array) old('order_item_ids')) : true)><span class="ds-choice__text"><span class="ds-choice__label">{{ $l->displayName() }} x{{ $l->quantity }}</span></span></label>@endforeach
                                        <p class="sd-quiet">Send the rest later as a second shipment, each with its own tracking number.</p>
                                    </fieldset>
                                @endif
                                <div class="sd-fields sd-fields--two">
                                    <x-ds.select label="Carrier" name="carrier" id="ship-carrier" :options="$carriers + ['other' => 'Another carrier or your own delivery']" :selected="old('carrier')" placeholder="Choose a carrier" :error="$errors->first('carrier_key') ?: $errors->first('carrier')" />
                                    <x-ds.input label="Carrier name, if it is not in the list" name="carrier_name" id="ship-carrier-name" :value="old('carrier_name')" maxlength="80" hint="Shown to the buyer as typed. No tracking link is made for it." />
                                    <x-ds.input label="Tracking number" name="tracking_number" id="ship-tracking" :value="old('tracking_number')" maxlength="80" hint="Optional but buyers ask for it. Letters, numbers and dashes." autocomplete="off" />
                                    <x-ds.input label="Note for the buyer" name="note" id="ship-note" :value="old('note')" maxlength="255" />
                                    <x-ds.input label="Expected delivery from" name="estimated_from" id="ship-from" type="date" :value="old('estimated_from', $group->estimated_from?->toDateString())" hint="Pre-filled from the method the buyer chose. An estimate, not a promise." />
                                    <x-ds.input label="Expected delivery by" name="estimated_to" id="ship-to" type="date" :value="old('estimated_to', $group->estimated_to?->toDateString())" />
                                </div>
                                <div><x-ds.button type="submit" variant="primary" icon="truck">Ship and tell the buyer</x-ds.button></div>
                            </form>
                        @endif
                    </section>
                @endif

                @if ($out->isNotEmpty())
                    <section aria-labelledby="o-arrive" class="sd-stack">
                        <h2 id="o-arrive" class="sd-h2">{{ $collection ? 'Has the buyer collected it?' : 'Has it arrived?' }}</h2>
                        <div class="sd-do">
                            <p class="sd-muted">{{ $collection ? 'Mark it collected once the buyer has it. The buyer can confirm too.' : 'Mark it delivered once the carrier confirms it. The buyer can confirm it as well.' }} Delivery starts the return window and the clock on releasing your earnings.</p>
                            <form method="POST" action="{{ route('seller.orders.deliver', $group) }}">@csrf<x-ds.button type="submit" variant="primary" icon="package-check">{{ $collection ? 'Mark as collected' : 'Mark as delivered' }}</x-ds.button></form>
                        </div>
                    </section>
                @endif

                @if ($group->shipments->isNotEmpty())
                    <section aria-labelledby="o-ship" class="sd-stack">
                        <h2 id="o-ship" class="sd-h2">Shipments</h2>
                        @foreach ($group->shipments as $sh)
                            <div class="sd-do">
                                <h3>{{ $sh->carrier_name ?: 'Carrier not named' }}@if ($sh->tracking_number): <span class="sd-code">{{ $sh->tracking_number }}</span>@endif</h3>
                                <p class="sd-muted">Shipped {{ $sh->shipped_at?->format('j M Y H:i') }}@if ($sh->estimated_to), expected by {{ $sh->estimated_to->format('j M') }}@endif. The buyer sees this marked as coming from you.
                                    @if ($sh->tracking_url) <a href="{{ $sh->tracking_url }}" rel="noopener noreferrer" target="_blank">Track with the carrier</a>@elseif ($sh->tracking_number) No tracking link: the carrier is not on Emall's list, so the number is shown as text.@endif</p>
                                @if ($sh->events->isNotEmpty())<ul class="sd-events">@foreach ($sh->events as $ev)<li><span>{{ $ev->description }}@if ($ev->location), {{ $ev->location }}@endif</span><time datetime="{{ $ev->occurred_at->toIso8601String() }}">{{ $ev->occurred_at->format('j M Y H:i') }}</time></li>@endforeach</ul>@endif
                                <form method="POST" action="{{ route('seller.shipments.events.store', $sh) }}" class="sd-stack sd-stack--tight" novalidate>@csrf
                                    <div class="sd-fields sd-fields--two">
                                        <x-ds.input label="Add a tracking update" name="description" :id="'ev-d-'.$sh->id" maxlength="190" placeholder="Handed to the courier" :error="$errors->first('description')" />
                                        <x-ds.input label="Where (optional)" name="location" :id="'ev-l-'.$sh->id" maxlength="120" />
                                    </div>
                                    <div><x-ds.button type="submit" variant="quiet" size="sm" icon="plus">Add update</x-ds.button></div>
                                </form>
                                <details><summary class="ds-btn ds-btn--link" style="cursor: pointer;">Correct the carrier or tracking number</summary>
                                    <form method="POST" action="{{ route('seller.shipments.update', $sh) }}" class="sd-stack" novalidate>@csrf @method('PATCH')
                                        <div class="sd-fields sd-fields--two">
                                            <x-ds.select label="Carrier" name="carrier" :id="'tr-c-'.$sh->id" :options="$carriers + ['other' => 'Another carrier or your own delivery']" :selected="$sh->carrier_key ?: ($sh->carrier_name ? 'other' : '')" placeholder="Choose a carrier" />
                                            <x-ds.input label="Carrier name, if not in the list" name="carrier_name" :id="'tr-n-'.$sh->id" :value="$sh->carrier_key ? '' : $sh->carrier_name" maxlength="80" />
                                            <x-ds.input label="Tracking number" name="tracking_number" :id="'tr-t-'.$sh->id" :value="$sh->tracking_number" maxlength="80" autocomplete="off" />
                                        </div>
                                        <div><x-ds.button type="submit" variant="quiet" size="sm">Save</x-ds.button></div>
                                    </form>
                                </details>
                            </div>
                        @endforeach
                    </section>
                @endif

                @if ($cancellable->isNotEmpty())
                    <section aria-labelledby="o-cancel" class="sd-stack">
                        <h2 id="o-cancel" class="sd-h2">Cancel items</h2>
                        <details class="sd-do" @if ($errors->has('item_ids') || $errors->has('reason')) open @endif>
                            <summary style="cursor: pointer; font-weight: 650;">I cannot send some of these</summary>
                            <form method="POST" action="{{ route('seller.orders.cancel', $group) }}" class="sd-stack" novalidate>
                                @csrf
                                <fieldset class="ds-fieldset"><legend>Items to cancel</legend>
                                    @foreach ($cancellable as $l)<label class="ds-choice"><input type="checkbox" name="item_ids[]" value="{{ $l->id }}" @checked(in_array($l->id, (array) old('item_ids')))><span class="ds-choice__text"><span class="ds-choice__label">{{ $l->displayName() }} x{{ $l->quantity }}</span></span></label>@endforeach
                                    @error('item_ids')<p class="ds-field__error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>@enderror
                                </fieldset>
                                <x-ds.textarea label="Why? The buyer is told." name="reason" id="cancel-reason" rows="3" maxlength="255" :value="old('reason')" :required="true" />
                                <p class="sd-quiet">The items go back into stock. If the buyer already paid they are refunded and your earnings are reduced to match. An item that has been shipped cannot be cancelled: the buyer would send it back as a return.</p>
                                <div><x-ds.button type="submit" variant="danger">Cancel selected items</x-ds.button></div>
                            </form>
                        </details>
                    </section>
                @endif
            </div>

            <aside class="sd-stack">
                <section aria-labelledby="o-to" class="sd-stack sd-stack--tight">
                    <h2 id="o-to" class="sd-h3">Send to</h2>
                    @if ($address)
                        <x-ds.address-block :name="$address['name']" :lines="$address['lines']" :phone="$address['phone']" />
                        @if ($address['instructions'])<p class="sd-muted">Buyer's note: {{ $address['instructions'] }}</p>@endif
                        <p class="sd-quiet">Use this address to post the parcel, and for nothing else. It is hidden once there is nothing left to ship.</p>
                    @elseif ($collection)
                        <p class="sd-muted">The buyer collects from you. No address is needed.</p>
                    @else
                        <p class="sd-muted">Hidden. The address is shown only while something is left to ship.</p>
                    @endif
                </section>
                <section class="sd-stack sd-stack--tight" aria-labelledby="o-method">
                    <h2 id="o-method" class="sd-h3">Delivery chosen</h2>
                    <x-ds.dl>
                        <dt>Method</dt><dd>{{ $group->method_name ?: 'Arranged with you' }}</dd>
                        <dt>Packing time</dt><dd>{{ $group->handling_days }} {{ $group->handling_days === 1 ? 'working day' : 'working days' }}</dd>
                        @if ($group->estimated_from)<dt>Buyer was told</dt><dd>An estimate of {{ $group->estimated_from->format('j M') }}@if ($group->estimated_to) to {{ $group->estimated_to->format('j M') }}@endif</dd>@endif
                        <dt>Fee paid by buyer</dt><dd>{{ $by($group->delivery_cents) }}</dd>
                    </x-ds.dl>
                </section>
                <section class="sd-stack sd-stack--tight" aria-labelledby="o-earn">
                    <h2 id="o-earn" class="sd-h3">What you earn</h2>
                    @forelse ($ledger as $e)
                        <x-ds.dl>
                            <dt>{{ $e->kind === 'sale' ? 'Sale' : 'Adjustment' }}</dt><dd>{{ Shop::money($e->gross_cents) }}</dd>
                            <dt>Emall's fee{{ (float) $e->commission_percent > 0 ? ' ('.rtrim(rtrim(number_format((float) $e->commission_percent, 2), '0'), '.').'%)' : '' }}</dt><dd>{{ Shop::money(-$e->commission_cents) }}</dd>
                            <dt>To you</dt><dd><strong>{{ Shop::money($e->net_cents) }}</strong></dd>
                            <dt>Status</dt><dd>{{ ucfirst($e->status) }}@if ($e->release_at && $e->status === 'held'), released from {{ $e->release_at->format('j M') }}@endif</dd>
                        </x-ds.dl>
                        @unless ($loop->last)<hr class="sd-hr">@endunless
                    @empty
                        <p class="sd-muted">Nothing recorded yet. Your earnings for this parcel are added when the buyer has paid.</p>
                    @endforelse
                    <a class="ds-btn ds-btn--link" href="{{ route('seller.ledger') }}">Open ledger</a>
                </section>
            </aside>
        </div>

        <section aria-labelledby="o-notes" class="sd-stack">
            <h2 id="o-notes" class="sd-h2">Your notes</h2>
            <p class="sd-quiet">Private to you. The buyer never sees them.</p>
            @if ($notes->isNotEmpty())<ul class="sd-events">@foreach ($notes as $n)<li><span>{{ $n->body }}</span><time datetime="{{ $n->created_at->toIso8601String() }}">{{ $n->author?->name }}, {{ $n->created_at->format('j M Y H:i') }}</time></li>@endforeach</ul>@endif
            <form method="POST" action="{{ route('seller.orders.note', $group) }}" class="sd-stack sd-stack--tight" novalidate>@csrf
                <x-ds.textarea label="Add a note" name="body" id="note-body" rows="2" maxlength="1000" :error="$errors->first('body')" />
                <div><x-ds.button type="submit" variant="quiet" size="sm">Save note</x-ds.button></div>
            </form>
        </section>

        <section aria-labelledby="o-history" class="sd-stack">
            <h2 id="o-history" class="sd-h2">History</h2>
            @if ($history->isEmpty())<p class="sd-muted">Nothing has happened to this parcel yet.</p>
            @else<ul class="sd-events">@foreach ($history as $h)<li><span>{{ S::from($h->to_status)->label() }}@if ($h->note) <span class="sd-muted">({{ $h->note }})</span>@endif</span><time datetime="{{ $h->created_at->toIso8601String() }}">{{ $h->created_at->format('j M Y H:i') }}, {{ $h->actor_role }}</time></li>@endforeach</ul>@endif
        </section>
    </div>
</x-seller-layout>
