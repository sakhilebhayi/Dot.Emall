{{--
  ds.delivery-estimate: when it should arrive and what it costs, labelled as an estimate with its source.
  Props: range (required, e.g. "2 to 4 working days" or "Thursday 14 to Monday 18 November"), to=null (the place, e.g. "Johannesburg 2196"), costCents=null (null = not known, 0 = free),
         note='Estimate from the seller's handling time and delivery method.', method=null (e.g. "Courier").
--}}
@props(['range', 'to' => null, 'costCents' => null, 'note' => "Estimate from the seller's handling time and delivery method.", 'method' => null])
<div {{ $attributes->class('ds-delivery') }}>
    <x-ds.icon name="truck" :size="22" />
    <div>
        <p class="ds-delivery__main">{{ $range }}@if ($to) <span class="ds-muted" style="font-weight: 400;">to {{ $to }}</span>@endif</p>
        <p class="ds-price-block__note">
            @if ($costCents !== null){{ (int) $costCents === 0 ? 'Free delivery' : 'Delivery '.\App\Support\Shop::money((int) $costCents) }}@if ($method), {{ strtolower($method) }}@endif @endif
        </p>
    </div>
    @if ($note)<p class="ds-delivery__note">{{ $note }}</p>@endif
</div>
