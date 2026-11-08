<x-operator-layout>
    <x-slot:title>Synonyms</x-slot:title>
    <x-slot:heading>Synonyms</x-slot:heading>
    <x-slot:lead>Words that mean the same thing, so a search for one finds the other. A change takes effect straight away. Try a search in the box to see how the shop understands it.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Merchandising', 'href' => route('operator.merch.index')], ['label' => 'Synonyms']]" /></x-slot:breadcrumbs>

    <div class="op-split">
        <div class="ds-stack">
            <section class="op-block" aria-labelledby="test-h">
                <h2 id="test-h">Test a search</h2>
                <form method="GET" action="{{ route('operator.search.synonyms') }}" class="op-filters" role="search" aria-label="Test a search">
                    <x-ds.input label="What a shopper might type" name="test" type="search" :value="$test" hint="For example: takkies under R500" />
                    <x-ds.button type="submit" variant="quiet">See how it is understood</x-ds.button>
                </form>
                @if ($result)
                    @php($p = $result['parsed'])
                    <x-ds.dl>
                        <dt>Words kept</dt><dd>{{ $p->tokens ? implode(', ', $p->tokens) : 'None' }}</dd>
                        <dt>Read as</dt>
                        <dd>@forelse ($p->detected as $d){{ ucfirst($d['kind']) }}: {{ $d['text'] }}{{ ! $loop->last ? '; ' : '' }}@empty Nothing beyond plain words @endforelse</dd>
                        <dt>Synonyms used</dt>
                        <dd>@forelse ($p->synonymsUsed as $u){{ $u['from'] }} also finds {{ $u['to'] }}{{ ! $loop->last ? '; ' : '' }}@empty None applied @endforelse</dd>
                        <dt>Results</dt><dd class="op-num">{{ number_format($result['total']) }} {{ $result['total'] === 1 ? 'listing' : 'listings' }}@if ($result['titles']): {{ implode('; ', $result['titles']) }}@endif</dd>
                        @foreach ($result['notes'] as $note)<dt>The shop says</dt><dd>{{ $note }}</dd>@endforeach
                    </x-ds.dl>
                @endif
            </section>

            <section class="op-block" aria-labelledby="groups-h">
                <h2 id="groups-h">Groups ({{ $groups->count() }})</h2>
                @forelse ($groups as $key => $terms)
                    <div class="op-item">
                        <ul class="op-chips" aria-label="Words that mean the same">
                            @foreach ($terms as $syn)
                                <li>
                                    <form method="POST" action="{{ route('operator.search.synonyms.destroy', $syn) }}" class="op-inline">
                                        @csrf @method('DELETE')
                                        <span class="op-chips__word">{{ $syn->term }}</span>
                                        <button type="submit" class="ds-icon-btn" aria-label="Remove {{ $syn->term }} from this group"><x-ds.icon name="close" :size="16" /></button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="op-muted">No synonyms yet.</p>
                @endforelse
            </section>
        </div>

        <aside class="op-side" aria-label="Add synonyms">
            <section class="op-block">
                <h2>Add words that mean the same</h2>
                <form method="POST" action="{{ route('operator.search.synonyms.store') }}" class="ds-stack">
                    @csrf
                    @php($bag = $errors->getBag('synonym'))
                    <x-ds.textarea label="Words or phrases, separated by commas" name="terms" id="syn-terms" rows="3" :value="old('terms', $prefill)" :error="$bag->first('terms') ?: ''" hint="For example: bakkie, pickup truck, ute. Spelling and capital letters do not matter." required />
                    <x-ds.input label="Add to the group containing (optional)" name="join" id="syn-join" :value="old('join')" :error="$bag->first('join') ?: ''" hint="Leave empty to start a new group." />
                    <x-ds.button type="submit">Add</x-ds.button>
                </form>
            </section>
        </aside>
    </div>
</x-operator-layout>
