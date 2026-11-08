<x-operator-layout>
    <x-slot:title>What people searched</x-slot:title>
    <x-slot:heading>What people searched</x-slot:heading>
    <x-slot:lead>Searches that found nothing are the shop's missing products and missing synonyms. Queries that look like an e-mail address or a long number are never stored, and the log keeps a keyed hash of who, never an account.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Merchandising', 'href' => route('operator.merch.index')], ['label' => 'Searches']]" /></x-slot:breadcrumbs>

    <x-op.chips label="Period" :items="collect([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'])->map(fn ($l, $d) => ['label' => $l, 'href' => route('operator.search.queries', ['days' => $d]), 'current' => $days === $d])->values()->all()" />

    @if ($totals['searches'] === 0)
        <x-ds.empty-state title="No data yet" icon="search">Nobody has searched in this period, so there is nothing to report.</x-ds.empty-state>
    @else
        <p class="op-muted">{{ number_format($totals['searches']) }} searches, {{ number_format($totals['zero']) }} found nothing ({{ number_format($totals['zero'] / $totals['searches'] * 100, 1) }}%).</p>
        <div class="op-split">
            <section class="op-block" aria-labelledby="zero-h">
                <h2 id="zero-h">Found nothing</h2>
                @if ($zero->isEmpty())
                    <p class="op-muted">Every search in this period found something.</p>
                @else
                    <x-ds.table caption="Searches that found nothing">
                        <x-slot:head><tr><th scope="col">Search</th><th scope="col" class="is-num">Times</th><th scope="col" class="is-num">People</th><th scope="col">Last</th><th scope="col"><span class="ds-visually-hidden">Actions</span></th></tr></x-slot:head>
                        @foreach ($zero as $row)
                            <tr>
                                <td data-label="Search"><strong>{{ $row['query'] }}</strong></td>
                                <td data-label="Times" class="is-num">{{ $row['searches'] }}</td>
                                <td data-label="People" class="is-num">{{ $row['people'] }}</td>
                                <td data-label="Last"><x-op.age :at="$row['last_at']" /> ago</td>
                                <td data-label="Actions"><x-ds.button variant="quiet" size="sm" :href="route('operator.search.synonyms', ['term' => $row['query']])" aria-label="Create synonym for {{ $row['query'] }}">Create synonym</x-ds.button></td>
                            </tr>
                        @endforeach
                    </x-ds.table>
                @endif
            </section>
            <aside class="op-side" aria-labelledby="pop-h">
                <section class="op-block">
                    <h2 id="pop-h">Popular searches</h2>
                    <p class="op-muted">Searched by at least three different people in 30 days, and found something.</p>
                    @forelse ($popular as $row)
                        <p><strong>{{ $row['query'] }}</strong> <span class="op-muted op-num">{{ $row['people'] }} people</span></p>
                    @empty
                        <p class="op-muted">No search has reached three different people yet.</p>
                    @endforelse
                </section>
            </aside>
        </div>
    @endif
</x-operator-layout>
