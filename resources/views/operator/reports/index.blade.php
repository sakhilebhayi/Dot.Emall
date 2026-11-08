<x-operator-layout>
    <x-slot:title>Reports</x-slot:title>
    <x-slot:heading>Reports</x-slot:heading>
    <x-slot:lead>What people reported on listings, stores, reviews and questions. A report hides nothing by itself: a pile of reports must not be a way to silence a seller. Open an item to read every reason and decide.</x-slot:lead>

    @php $qs = fn (array $o) => route('operator.reports', array_filter(array_merge(['view' => $view === 'repeat' ? 'repeat' : null, 'status' => $status, 'type' => $type, 'reason' => $reason], $o), fn ($v) => $v !== null && $v !== '')); @endphp
    <x-op.chips label="View" :items="[
        ['label' => 'Reported items', 'href' => route('operator.reports'), 'current' => $view === 'items'],
        ['label' => 'Repeat offenders (90 days)', 'href' => route('operator.reports', ['view' => 'repeat']), 'current' => $view === 'repeat'],
    ]" />

    @if ($view === 'items')
        <x-op.chips label="Report status" :items="[
            ['label' => 'Open', 'href' => $qs(['status' => 'open']), 'current' => $status === 'open', 'count' => $counts['open'] ?? 0],
            ['label' => 'Settled', 'href' => $qs(['status' => 'settled']), 'current' => $status === 'settled', 'count' => ($counts['actioned'] ?? 0) + ($counts['dismissed'] ?? 0)],
            ['label' => 'All', 'href' => $qs(['status' => 'all']), 'current' => $status === 'all', 'count' => array_sum($counts)],
        ]" />
        <form method="GET" action="{{ route('operator.reports') }}" class="op-filters" aria-label="Filter reports">
            <input type="hidden" name="status" value="{{ $status }}">
            <x-ds.select label="About" name="type" :options="['review' => 'Reviews', 'question' => 'Questions', 'answer' => 'Answers', 'product' => 'Listings', 'store' => 'Stores']" :selected="$type" placeholder="Anything" />
            <x-ds.select label="Reason" name="reason" :options="['spam' => 'Spam', 'fake' => 'Fake', 'offensive' => 'Offensive', 'off_topic' => 'Off topic', 'personal_info' => 'Personal information', 'other' => 'Other']" :selected="$reason" placeholder="Any reason" />
            <x-ds.button type="submit" variant="quiet">Filter</x-ds.button>
        </form>

        @if ($groups->isEmpty())
            <x-ds.empty-state title="{{ $status === 'open' ? 'No open reports' : 'No reports match' }}" icon="flag">{{ $status === 'open' ? 'When someone reports a listing, store, review or question it appears here.' : 'Change the filters above.' }}</x-ds.empty-state>
        @else
            <ul class="op-items" aria-label="Reported items">
                @foreach ($groups as $g)
                    <li class="op-item">
                        <div class="op-item__head">
                            <a class="op-row-link" href="{{ route('operator.reports.show', ['type' => $g['type'], 'id' => $g['id']]) }}">{{ $g['label'] }}</a>
                            @if ($g['status'] === 'open')<x-ds.badge tone="cau" icon="flag">Open</x-ds.badge>@else<x-ds.badge :tone="$g['status'] === 'actioned' ? 'crit' : 'neutral'" :icon="$g['status'] === 'actioned' ? 'ban' : 'check-circle'">{{ $g['status'] === 'actioned' ? 'Taken down' : 'Dismissed' }}</x-ds.badge>@endif
                        </div>
                        <div class="op-item__meta">
                            <span class="op-num">{{ $g['reporters'] }} {{ \Illuminate\Support\Str::plural('person', $g['reporters']) }} · {{ $g['reports'] }} {{ \Illuminate\Support\Str::plural('report', $g['reports']) }}</span>
                            <span>{{ collect($g['reasons'])->map(fn ($n, $r) => str_replace('_', ' ', $r).($n > 1 ? " ($n)" : ''))->implode(', ') }}</span>
                            <span>First <x-op.age :at="$g['first_at']" /> ago</span>
                        </div>
                        @if ($g['excerpt'])<p class="op-quote">{{ $g['excerpt'] }}</p>@endif
                        @if ($g['resolution'])<p class="op-quote">{{ $g['resolution'] }}<small>Outcome note, <x-op.age :at="$g['handled_at']" /> ago</small></p>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        <p class="op-lead">People whose posts, listings or stores were taken down on report more than once in the last 90 days. Each item counts once, however many people reported it. This is for judgement, not an automatic penalty.</p>
        @if ($offenders->isEmpty())
            <x-ds.empty-state title="No repeat offenders" icon="check-circle">Nobody has had two or more items taken down in the last 90 days.</x-ds.empty-state>
        @else
            <x-ds.table caption="Repeat offenders">
                <x-slot:head><tr><th scope="col">Person</th><th scope="col" class="is-num">Items taken down</th><th scope="col">What</th><th scope="col">Latest</th></tr></x-slot:head>
                @foreach ($offenders as $o)
                    <tr>
                        <td data-label="Person"><a class="op-row-link" href="{{ route('operator.people.user', $o['user']) }}">{{ $o['user']->name }}</a></td>
                        <td data-label="Items taken down" class="is-num">{{ $o['items'] }}</td>
                        <td data-label="What">{{ implode(', ', $o['types']) }}</td>
                        <td data-label="Latest"><x-op.age :at="$o['last_at']" /> ago</td>
                    </tr>
                @endforeach
            </x-ds.table>
        @endif
    @endif
</x-operator-layout>
