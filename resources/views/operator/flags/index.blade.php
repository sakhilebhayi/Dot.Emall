<x-operator-layout>
    <x-slot:title>Flagged listings</x-slot:title>
    <x-slot:heading>Listings the rules flagged</x-slot:heading>
    <x-slot:lead>A review aid. Simple rules put a listing in front of you with the reason shown. Nothing is hidden, unlisted or banned automatically; you decide, and a listing you judge fine stays dismissed for that rule.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Listings', 'href' => route('operator.products.index')], ['label' => 'Flagged']]" /></x-slot:breadcrumbs>

    @php
        $chips = [['label' => 'All rules', 'href' => route('operator.flags.index'), 'current' => $rule === null, 'count' => $ruleCounts->sum()]];
        foreach (\App\Services\Operator\ListingFlags::RULES as $key => $label) { $chips[] = ['label' => $label, 'href' => route('operator.flags.index', ['rule' => $key]), 'current' => $rule === $key, 'count' => $ruleCounts[$key] ?? 0]; }
    @endphp
    <x-op.chips label="Rule" :items="$chips" />

    <details class="op-block">
        <summary class="ds-btn ds-btn--link ds-btn--sm">The rules in force</summary>
        @foreach ($rules as $key => $text)<p class="op-rule"><span class="op-rule__name">{{ \App\Services\Operator\ListingFlags::RULES[$key] }}</span><span class="op-muted">{{ $text }}</span></p>@endforeach
    </details>

    @if ($flags->isEmpty())
        <x-ds.empty-state title="Nothing is flagged" icon="check-circle">No pending or recently listed product breaks a rule. The scan covers pending listings and those listed or edited in the last {{ config('emall.flags.scan_days') }} days.</x-ds.empty-state>
    @else
        <ul class="op-items" aria-label="Flagged listings">
            @foreach ($flags as $flag)
                @php($p = $flag['product'])
                <li class="op-item">
                    <div class="op-item__head">
                        <a class="op-row-link" href="{{ route('operator.products.show', $p) }}">{{ $p->name }}</a>
                        <span class="op-num">{{ \App\Support\Shop::amount($p->price) }}</span>
                    </div>
                    <div class="op-item__meta"><span>{{ $p->store->name }}</span><x-ds.badge tone="cau" icon="flag">{{ $flag['rule_label'] }}</x-ds.badge><x-ds.status kind="product" :state="$p->status" /></div>
                    <p class="op-item__body">{{ $flag['detail'] }}</p>
                    <div class="op-decisions">
                        <x-ds.button variant="quiet" size="sm" :href="route('operator.products.show', $p)">Open the listing</x-ds.button>
                        <x-op.decide :bag="'flag-'.$p->id.'-'.$flag['rule']" :action="route('operator.flags.dismiss', $p)" summary="It is fine" submit="Mark as fine" field="note" label="Why it is fine" hint="Kept with the listing, so nobody checks it twice." tone="quiet">
                            <input type="hidden" name="rule" value="{{ $flag['rule'] }}">
                        </x-op.decide>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-operator-layout>
