<x-operator-layout>
    <x-slot:title>People</x-slot:title>
    <x-slot:heading>Find a person, store or order</x-slot:heading>
    <x-slot:lead>Support's read-only view. Searching shows names and masked e-mail addresses. Opening a record shows personal data, so each opening is written to the access log with the purpose you choose (POPIA).</x-slot:lead>
    <x-slot:actions><x-ds.button variant="quiet" :href="route('operator.team')" icon="shield-check">Operators</x-ds.button></x-slot:actions>

    <form method="GET" action="{{ route('operator.people.index') }}" class="op-filters" role="search" aria-label="Find a record">
        <x-ds.select label="Look for" name="type" :options="['user' => 'A person', 'store' => 'A store', 'order' => 'An order']" :selected="$type" />
        <x-ds.input label="Number, name or e-mail" name="q" type="search" :value="$q" hint="An order is found by its number or the buyer's e-mail." />
        <x-ds.select label="Why are you looking?" name="purpose" :options="$purposes" :selected="$purpose" />
        <x-ds.button type="submit">Search</x-ds.button>
    </form>

    @if ($results !== null)
        @if ($results->isEmpty())
            <x-ds.empty-state title="Nothing found" icon="search">Nothing matches "{{ $q }}". Try the number, or the full e-mail address.</x-ds.empty-state>
        @else
            <ul class="op-items" aria-label="Results">
                @foreach ($results as $r)
                    <li class="op-item">
                        <div class="op-item__head">
                            <a class="op-row-link" href="{{ route('operator.people.'.$r['type'], [$r['type'] => $r['id'], 'purpose' => $purpose]) }}">{{ $r['title'] }}</a>
                            <span class="op-muted">{{ $r['sub'] }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="op-muted">Opening a result is logged as: {{ $purposes[$purpose] }}.</p>
        @endif
    @endif

    <section class="op-block" aria-labelledby="log-h">
        <h2 id="log-h">Last records opened</h2>
        @forelse ($accesses as $a)
            <p class="op-muted"><strong>{{ $a->actor_name }}</strong> opened {{ $a->subject_label }} ({{ $a->subject_type }}), <x-op.age :at="$a->created_at" /> ago. {{ $a->purpose }}</p>
        @empty
            <p class="op-muted">No record has been opened yet.</p>
        @endforelse
        <a href="{{ route('operator.audit', ['tab' => 'access']) }}">Open the full access log</a>
    </section>
</x-operator-layout>
