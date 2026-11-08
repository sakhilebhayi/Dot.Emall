{{--
  ds.price: money as one run of tabular, lining numerals in the condensed width ("R749.00": the zero is a zero, never a lowercase o).
  Props: cents=null (integer) or amount=null (decimal string as stored); size='md' (sm, md, lg, xl); unit=null (e.g. "R28.81 per 100 g", only when the product really has a unit);
         previousCents=null (the REAL lowest price charged in the last 30 days, from the price history; never a seller-typed "was" price) with previousNote;
         vatIncluded=false ("Incl. VAT" line); note=null (any other short line).
  The visible figure is aria-hidden; screen readers get the plain amount once.
--}}
@props(['cents' => null, 'amount' => null, 'size' => 'md', 'unit' => null, 'previousCents' => null, 'previousNote' => 'Lowest price in the last 30 days', 'vatIncluded' => false, 'note' => null])
@php
    $c = $cents ?? \App\Support\Money::toCents($amount);
    $text = \App\Support\Shop::money((int) $c);
    preg_match('/^(-?)(\D*?)([\d,]+)(\.\d{2})$/', $text, $m);
    $cur = ($m[1] ?? '').($m[2] ?? '');
    $whole = $m[3] ?? $text;
    $dec = $m[4] ?? '';
    $figure = '<span class="ds-visually-hidden">'.e($text).'</span><span class="ds-price ds-price--'.e($size).'" aria-hidden="true"><span class="ds-price__cur">'.e($cur).'</span><span class="ds-price__whole">'.e($whole).'</span><span class="ds-price__cents">'.e($dec).'</span></span>';
    $extras = $unit || $previousCents || $vatIncluded || $note;
@endphp
@if ($extras)
    <span {{ $attributes->class('ds-price-block') }}>
        {!! $figure !!}
        @if ($previousCents)<span class="ds-price-block__ref">{{ $previousNote }}: <s>{{ \App\Support\Shop::money((int) $previousCents) }}</s></span>@endif
        @if ($unit)<span class="ds-price-block__note">{{ $unit }}</span>@endif
        @if ($vatIncluded)<span class="ds-price-block__note">Includes VAT</span>@endif
        @if ($note)<span class="ds-price-block__note">{{ $note }}</span>@endif
    </span>
@else
    <span {{ $attributes }}>{!! $figure !!}</span>
@endif
