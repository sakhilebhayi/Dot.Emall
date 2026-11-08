{{--
  ds.price-label: THE signature element. A shelf-edge label that states what the thing costs and what you will actually pay: price, delivery, total.
  Real data only. Where delivery is not known yet it says so and what is needed; it never guesses.
  Props: priceCents (required, integer); deliveryCents=null (null = not known yet, 0 = free); deliveryNote=null (e.g. "To Johannesburg, 2 to 4 working days");
         deliveryPending='Enter a delivery address to see this' (shown while deliveryCents is null); totalCents=null (default: price + delivery when delivery is known);
         from=null (the store or brand line above the price); title=null; unit=null; previousCents=null; vatIncluded=true; compact=false; behind='var(--ground)' (the colour behind
         the label, so the notches match: pass var(--surface) when it sits on a panel).
  Slot: optional extra row(s) as <div class="ds-label__row"><dt>..</dt><dd>..</dd></div>.
--}}
@props(['priceCents', 'deliveryCents' => null, 'deliveryNote' => null, 'deliveryPending' => 'Enter a delivery address to see this', 'totalCents' => null, 'from' => null, 'title' => null, 'unit' => null, 'previousCents' => null, 'vatIncluded' => true, 'compact' => false, 'behind' => null])
@php
    $total = $totalCents ?? ($deliveryCents !== null ? (int) $priceCents + (int) $deliveryCents : null);
@endphp
<div {{ $attributes->class(['ds-label', 'ds-label--compact' => $compact]) }} @if ($behind) style="--label-behind: {{ $behind }}" @endif role="group" aria-label="Price and delivery">
    <div class="ds-label__main">
        @if ($from)<span class="ds-label__from">{{ $from }}</span>@endif
        @if ($title)<span class="ds-label__from" style="color: var(--ink); font-weight: 650;">{{ $title }}</span>@endif
        <x-ds.price :cents="$priceCents" size="xl" :unit="$unit" :previous-cents="$previousCents" />
    </div>
    <div class="ds-label__perf" aria-hidden="true"></div>
    <dl class="ds-label__rows">
        <div class="ds-label__row">
            <dt>Delivery</dt>
            <dd>
                @if ($deliveryCents === null)
                    <span class="ds-muted" style="font-weight: 400;">{{ $deliveryPending }}</span>
                @elseif ((int) $deliveryCents === 0)
                    Free
                @else
                    {{ \App\Support\Shop::money((int) $deliveryCents) }}
                @endif
                @if ($deliveryNote)<small>{{ $deliveryNote }}</small>@endif
            </dd>
        </div>
        {{ $slot }}
        <div class="ds-label__row ds-label__row--total">
            <dt>Total</dt>
            <dd>
                @if ($total !== null)<x-ds.price :cents="$total" size="lg" />@else<span class="ds-muted" style="font-weight: 400;">Not known yet</span>@endif
                @if ($vatIncluded && $total !== null)<small>Includes VAT</small>@endif
            </dd>
        </div>
    </dl>
</div>
