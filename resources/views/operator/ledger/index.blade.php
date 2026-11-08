<x-operator-layout>
    <x-slot:title>Ledger</x-slot:title>
    <x-slot:heading>Ledger reconciliation</x-slot:heading>
    <x-slot:lead>Orders, payments, refunds and the seller ledger are compared. It only reads: it finds disagreements and repairs nothing. It runs every night, and you can run it now.</x-slot:lead>
    <x-slot:actions>
        <form method="POST" action="{{ route('operator.ledger.run') }}">@csrf<x-ds.button type="submit" icon="check-circle">Run it now</x-ds.button></form>
    </x-slot:actions>

    @if ($latest === null)
        <x-ds.empty-state title="It has not run yet" icon="help">No reconciliation is on record, so nobody knows whether the books agree. Run it now, or wait for the nightly run (it needs the server's scheduler).</x-ds.empty-state>
    @else
        @if ($latest->mismatch_count > 0)
            <x-ds.alert tone="crit" title="{{ $latest->mismatch_count }} {{ \Illuminate\Support\Str::plural('mismatch', $latest->mismatch_count) }} at the last run">Checked {{ number_format($latest->checked) }} paid orders {{ $latest->ran_at->diffForHumans() }}. Each line below says what was expected and what was found.</x-ds.alert>
        @else
            <x-ds.alert tone="pos" title="Everything agreed">Checked {{ number_format($latest->checked) }} paid orders {{ $latest->ran_at->diffForHumans() }}{{ $latest->days ? ' (orders paid in the last '.$latest->days.' days)' : '' }}.</x-ds.alert>
        @endif

        @if ($mismatches->isNotEmpty())
            <section class="op-block" aria-labelledby="mm-h">
                <h2 id="mm-h">Mismatches</h2>
                <x-ds.table caption="Mismatches at the last run">
                    <x-slot:head><tr><th scope="col">Order</th><th scope="col">Check</th><th scope="col" class="is-num">Expected</th><th scope="col" class="is-num">Found</th><th scope="col">What it means</th></tr></x-slot:head>
                    @foreach ($mismatches as $m)
                        <tr>
                            <td data-label="Order">@if ($m['order_id'])<a class="op-row-link" href="{{ route('operator.people.order', $m['order_id']) }}">#{{ $m['order_id'] }}</a>@else None @endif</td>
                            <td data-label="Check"><span class="op-code">{{ $m['check'] }}</span></td>
                            <td data-label="Expected" class="is-num">{{ \App\Support\Shop::money((int) $m['expected']) }}</td>
                            <td data-label="Found" class="is-num"><strong>{{ \App\Support\Shop::money((int) $m['actual']) }}</strong></td>
                            <td data-label="What it means">{{ $m['detail'] }}</td>
                        </tr>
                    @endforeach
                </x-ds.table>
                @if ($latest->mismatch_count > $mismatches->count())<p class="op-muted">The first {{ $mismatches->count() }} of {{ $latest->mismatch_count }} are listed. Run <span class="op-code">php artisan emall:reconcile</span> on the server for the rest.</p>@endif
            </section>
        @endif
    @endif

    @if ($history->isNotEmpty())
        <section class="op-block" aria-labelledby="hist-h">
            <h2 id="hist-h">Recent runs</h2>
            <x-ds.table caption="Recent reconciliation runs">
                <x-slot:head><tr><th scope="col">When</th><th scope="col">Started by</th><th scope="col" class="is-num">Orders checked</th><th scope="col" class="is-num">Mismatches</th></tr></x-slot:head>
                @foreach ($history as $run)
                    <tr>
                        <td data-label="When">{{ $run->ran_at->format('d M Y H:i') }}</td>
                        <td data-label="Started by">{{ ['command' => 'The command or the nightly run', 'operator' => 'An operator', 'schedule' => 'The nightly run'][$run->trigger] ?? $run->trigger }}</td>
                        <td data-label="Orders checked" class="is-num">{{ number_format($run->checked) }}</td>
                        <td data-label="Mismatches" class="is-num">{{ $run->mismatch_count > 0 ? $run->mismatch_count : 'None' }}</td>
                    </tr>
                @endforeach
            </x-ds.table>
        </section>
    @endif
</x-operator-layout>
