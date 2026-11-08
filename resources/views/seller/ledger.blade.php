@php
    use App\Support\Shop;
    $cur = Shop::currency();
    $bal = $balance[$cur] ?? ['held' => 0, 'released' => 0, 'commission' => 0];
    $others = collect($balance)->except($cur);
    $pct = fn ($p) => rtrim(rtrim(number_format((float) $p, 2), '0'), '.');
    $tabs = [null => 'All', 'held' => 'Held', 'released' => 'Released', 'reversed' => 'Reversed'];
@endphp
<x-seller-layout>
    <x-slot:title>Ledger</x-slot:title>
    <x-slot:heading>Ledger</x-slot:heading>
    <x-slot:lead>What you have earned on Emall, what is waiting out the return window and what has been released.</x-slot:lead>
    <x-slot:actions><x-ds.button variant="quiet" :href="route('seller.ledger.export')" icon="upload">Download CSV</x-ds.button></x-slot:actions>

    <div class="sd-page">
        <section class="sd-cols" aria-labelledby="l-bal">
            <div class="sd-stack">
                <h2 id="l-bal" class="sd-h2">Where you stand</h2>
                @if (empty($balance))
                    <p class="sd-muted"><strong>No data yet.</strong> Your first sale is recorded here the moment a buyer pays.</p>
                @else
                    <div class="sd-lead">
                        <div class="sd-lead__figure">
                            <span class="sd-quiet">Released to you, after Emall's fee</span>
                            <span class="sd-lead__value">{{ Shop::money($bal['released']) }}</span>
                            <span class="sd-muted">Plus {{ Shop::money($bal['held']) }} held while buyers can still return their items. {{ $next ? 'The first of it is released from '.$next->format('j M Y').'.' : '' }}</span>
                        </div>
                        <div class="sd-facts">
                            <x-ds.stat label="Held" :value="Shop::money($bal['held'])" :note="'Released '.$releaseDays.' days after delivery'" />
                            <x-ds.stat label="Released" :value="Shop::money($bal['released'])" />
                            <x-ds.stat label="Emall's fee so far" :value="Shop::money($bal['commission'])" :note="$percent > 0 ? $pct($percent).'% of the item price on new sales' : 'No fee on new sales at the moment'" />
                        </div>
                    </div>
                    @foreach ($others as $code => $b)<p class="sd-quiet">{{ $code }}: held {{ Shop::money($b['held'], $code) }}, released {{ Shop::money($b['released'], $code) }}.</p>@endforeach
                @endif
            </div>
            <aside class="sd-stack" aria-labelledby="l-how">
                <h2 id="l-how" class="sd-h3">How payouts work</h2>
                <p class="sd-muted">Each sale is written to this ledger when the buyer pays. It is released {{ $releaseDays }} days after the whole parcel is delivered or collected, unless a return on it is still open.</p>
                <p class="sd-muted"><strong>Emall does not pay out automatically yet.</strong> Released earnings are paid to you by arrangement with the Emall team. Nothing on this page means money has reached your bank account.</p>
                <p class="sd-muted">A cancelled or refunded item adds a reversal that reduces what you earned. Rows are never edited: a correction is a new row.</p>
                <p class="sd-quiet">Emall checks orders, payments, refunds and this ledger against each other. If an entry does not add up, the Emall team follows it up with you. This page does not show a per-entry check result.</p>
            </aside>
        </section>

        <section aria-labelledby="l-entries" class="sd-stack">
            <h2 id="l-entries" class="sd-h2">Entries</h2>
            <nav class="sd-tabnav" aria-label="Ledger status">
                @foreach ($tabs as $key => $label)<a href="{{ route('seller.ledger', array_filter(['status' => $key])) }}" @if ((string) $status === (string) $key) aria-current="page" @endif>{{ $label }}</a>@endforeach
            </nav>
            @if ($entries->isEmpty())
                <p class="sd-muted">No entries here.</p>
            @else
                <x-ds.table caption="Ledger entries, newest first" :stack="true">
                    <x-slot:head><tr><th scope="col">Recorded</th><th scope="col">Order</th><th scope="col">What</th><th scope="col" class="is-num">Gross</th><th scope="col" class="is-num">Fee</th><th scope="col" class="is-num">To you</th><th scope="col">Status</th></tr></x-slot:head>
                    @foreach ($entries as $e)
                        <tr>
                            <td data-label="Recorded">{{ $e->created_at->format('j M Y') }}</td>
                            <td data-label="Order"><a href="{{ route('seller.orders.show', $e->order_group_id) }}">#{{ str_pad((string) $e->order_id, 5, '0', STR_PAD_LEFT) }}</a></td>
                            <td data-label="What">{{ $e->kind === 'sale' ? 'Sale' : 'Reversal' }}<br><span class="sd-quiet">{{ $e->reason }}</span></td>
                            <td data-label="Gross" class="is-num">{{ Shop::money($e->gross_cents, $e->currency) }}</td>
                            <td data-label="Fee" class="is-num">{{ Shop::money(-$e->commission_cents, $e->currency) }}<br><span class="sd-quiet">{{ (float) $e->commission_percent > 0 ? $pct($e->commission_percent).'%' : 'No fee' }}</span></td>
                            <td data-label="To you" class="is-num"><strong>{{ Shop::money($e->net_cents, $e->currency) }}</strong></td>
                            <td data-label="Status">{{ ucfirst($e->status) }}@if ($e->status === 'held' && $e->release_at)<br><span class="sd-quiet">From {{ $e->release_at->format('j M Y') }}</span>@elseif ($e->status === 'held')<br><span class="sd-quiet">Starts when delivered</span>@elseif ($e->released_at)<br><span class="sd-quiet">{{ $e->released_at->format('j M Y') }}</span>@endif</td>
                        </tr>
                    @endforeach
                </x-ds.table>
                <x-ds.pagination :paginator="$entries" />
            @endif
            <p class="sd-quiet">Gross is your items after any discount plus the delivery fee for the parcel. The fee is taken on the items only, at the percentage in force when the buyer paid, and a later change never rewrites an earlier sale.</p>
        </section>
    </div>
</x-seller-layout>
