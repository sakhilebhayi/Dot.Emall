{{--
  ds.address-block: a delivery or billing address as an <address>. Props: name (required), lines (required: list of strings), phone=null, isDefault=false, card=false (a bordered card with the actions slot).
  Slot: actions (edit, delete, make default) when card=true.
--}}
@props(['name', 'lines', 'phone' => null, 'isDefault' => false, 'card' => false])
<div @if ($card) {{ $attributes->class('ds-address-card') }} @else {{ $attributes }} @endif>
    <address class="ds-address">
        <span class="ds-address__name">{{ $name }}@if ($isDefault) <x-ds.badge tone="info">Default</x-ds.badge>
@endif</span>
        @foreach (array_filter($lines) as $line)<span>{{ $line }}</span>@endforeach
        @if ($phone)<span class="ds-address__meta">{{ $phone }}</span>@endif
    </address>
    @if ($card && isset($actions))<div class="ds-address-card__actions">{{ $actions }}</div>@endif
</div>
