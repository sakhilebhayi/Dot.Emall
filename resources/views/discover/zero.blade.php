{{-- The honest empty page: what was searched, what dropping a filter would give, real popular searches and categories, the assistant, and the person's own recent views. Variables: $zero, $result, $search, $links, $q. --}}
<section class="disc-zero" aria-labelledby="zero-h">
    <h2 id="zero-h">Nothing matches that</h2>
    @if ($q !== '' && $search->filters() === [])
        <p>No listing contains what you typed: <strong>{{ $q }}</strong>. Only approved listings from open stores are searched, so a product that is pending review or sold out of a closed store will not appear.</p>
    @elseif ($result->appliedFilters)
        <p>Nothing is listed with all of these filters at once. Take one away to see what comes back.</p>
    @else
        <p>There is nothing listed here right now. Only approved listings from open stores appear.</p>
    @endif

    @if ($result->relaxations)
        <ul class="disc-relax">
            @foreach ($result->relaxations as $relax)
                <li><a href="{{ $links->without($relax['remove']) }}">Remove "{{ $relax['label'] }}" to see {{ number_format($relax['count']) }}</a></li>
            @endforeach
        </ul>
    @endif

    <div class="disc-zero__grid">
        <div>
            <h3>Describe it to Emall</h3>
            <p class="disc-muted">Say what you need in your own words, for example "a reliable laptop for university under R15,000". The answer comes only from the catalogue.</p>
            <form class="disc-ask" method="get" action="{{ route('assistant') }}">
                <label class="ds-visually-hidden" for="zero-ask">What are you looking for</label>
                <input class="ds-input" id="zero-ask" name="text" type="text" maxlength="400" value="{{ $q }}" placeholder="What do you need?">
                <x-ds.button type="submit" icon="sparkle">Ask Emall</x-ds.button>
            </form>
        </div>
        @if (! empty($zero['popular']))
            <div>
                <h3>Popular searches</h3>
                <ul class="disc-chips">
                    @foreach ($zero['popular'] as $row)<li><a class="disc-chip" href="{{ route('search', ['q' => $row['query']]) }}">{{ $row['query'] }}</a></li>@endforeach
                </ul>
                <p class="disc-muted">Searches that at least {{ config('discovery.suggest.popular_min_people', 3) }} different people ran and that found something.</p>
            </div>
        @endif
        @if (! empty($zero['categories']))
            <div>
                <h3>Browse a category</h3>
                <ul class="disc-chips">
                    @foreach ($zero['categories'] as $cat)<li><a class="disc-chip" href="{{ route('categories.show', $cat['slug']) }}">{{ $cat['name'] }}<span class="disc-count">{{ number_format($cat['rollup_count']) }}</span></a></li>@endforeach
                </ul>
            </div>
        @endif
    </div>

    @if (! empty($zero['viewed']))
        <x-disc-rail :set="$zero['viewed']" level="3" />
    @endif
</section>
