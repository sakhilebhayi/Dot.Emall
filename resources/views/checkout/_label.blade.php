{{--
  The total, as a shelf-edge label: what the items cost, what each seller charges to deliver, the discount and why, and the one figure to pay.
  Variables: $quote (App\Services\Cart\QuoteResult), $heading ('Total to pay'), $behind (colour behind the label).
--}}
@php
    use App\Support\Shop;
    $discountReason = \App\Services\Checkout\CartPage::describePromo($quote);
    $vatStores = collect($quote->groups)->filter(fn ($g) => $g['vat_registered'] && $g['vat_cents'] > 0);
@endphp
<div class="ds-label co-label" role="group" aria-label="{{ $heading ?? 'Total to pay' }}" @isset($behind) style="--label-behind: {{ $behind }}" @endisset>
    <div class="ds-label__main">
        <span class="ds-label__from">{{ $heading ?? 'Total to pay' }}</span>
        <x-ds.price :cents="$quote->totalCents" size="xl" />
        <span class="ds-price-block__note">Includes VAT where the seller charges it. This is the figure you pay, nothing is added later.</span>
    </div>
    <div class="ds-label__perf" aria-hidden="true"></div>
    <dl class="ds-label__rows">
        <div class="ds-label__row"><dt>Items</dt><dd>{{ Shop::money($quote->itemsCents) }}</dd></div>
        @if ($quote->discountCents > 0)
            <div class="ds-label__row">
                <dt>Discount @if ($quote->promo)<span class="ds-code">{{ $quote->promo->code }}</span>@endif</dt>
                <dd>{{ Shop::money(-$quote->discountCents) }}@if ($discountReason)<small>{{ $discountReason }}</small>@endif</dd>
            </div>
        @endif
        @foreach ($quote->groups as $g)
            <div class="ds-label__row">
                <dt>Delivery, {{ $g['store_name'] }}</dt>
                <dd>
                    @if (! $g['can_deliver'])
                        <span class="ds-muted" style="font-weight: 400;">Not available yet</span>
                    @elseif ($g['delivery_cents'] === 0)
                        Free
                    @else
                        {{ Shop::money($g['delivery_cents']) }}
                    @endif
                    @if ($g['selected'] && $g['can_deliver'])<small>{{ $g['selected']->name }}</small>@endif
                </dd>
            </div>
        @endforeach
        <div class="ds-label__row ds-label__row--total">
            <dt>Total to pay</dt>
            <dd><x-ds.price :cents="$quote->totalCents" size="lg" /></dd>
        </div>
        @foreach ($vatStores as $g)
            <div class="ds-label__row"><dt class="ds-muted" style="font-weight: 400;">VAT included, {{ $g['store_name'] }}</dt><dd class="ds-muted" style="font-weight: 400;">{{ Shop::money($g['vat_cents']) }}</dd></div>
        @endforeach
    </dl>
</div>
