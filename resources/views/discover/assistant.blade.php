{{--
  Ask Emall. A request, what was understood (each part a link that removes it or puts it back), a short list, and for every product two halves kept apart: "From the catalogue" (facts read from
  the catalogue) and "Our suggestion" (reasoning about them). Variables: $text, $without, $answer, $limited, $images, $chips, $searchParams, $removeFor, $askUrl.
--}}
@php
    $examples = ['A reliable laptop for university under R15,000', 'Cheapest air fryer in stock', 'Samsung phone under R8,000'];
    $mode = $answer['mode'] ?? null;
@endphp
<x-store-layout :robots="$text !== '' ? 'noindex, follow' : null">
    <x-slot:title>Ask Emall</x-slot:title>
    <x-slot:description>Describe what you need in your own words. Ask Emall searches the real catalogue and shows the facts, kept apart from its own suggestion.</x-slot:description>
    <x-slot:location><x-disc-region-chip /></x-slot:location>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Ask Emall']]" /></x-slot:breadcrumbs>
    <x-slot:heading>Ask Emall</x-slot:heading>
    <x-slot:lead>Say what you need and roughly what you can spend. The facts come from the catalogue; anything that is our reading of them is labelled.</x-slot:lead>

    <div class="disc-assist">
        <form class="disc-assist__form" method="get" action="{{ route('assistant') }}#answer">
            <x-ds.field label="What do you need?" id="assist-text" hint="For example: {{ $examples[0] }}">
                <textarea class="ds-textarea" id="assist-text" name="text" rows="2" maxlength="{{ config('discovery.assistant.max_text_length', 400) }}" aria-describedby="assist-text-hint" required>{{ $text }}</textarea>
            </x-ds.field>
            <div class="disc-assist__row">
                <x-ds.button type="submit" icon="sparkle">Ask Emall</x-ds.button>
                <ul class="disc-chips" aria-label="Try one of these">
                    @foreach ($examples as $example)<li><a class="disc-chip" href="{{ route('assistant', ['text' => $example]) }}#answer">{{ $example }}</a></li>@endforeach
                </ul>
            </div>
        </form>

        @if ($limited)
            <x-ds.alert tone="cau" title="That was a lot of questions">Please wait a minute and ask again. You can also use the search box above, which has no limit.</x-ds.alert>
        @endif

        @if ($answer)
            <section id="answer" class="disc-assist__answer" aria-labelledby="answer-h" tabindex="-1">
                <div class="disc-msg disc-msg--you"><p class="disc-msg__who">You asked</p><p>{{ $answer['request'] }}</p></div>

                <div class="disc-msg disc-msg--emall" role="region" aria-live="polite">
                    <h2 id="answer-h" class="disc-msg__who">Emall answered</h2>

                    @if ($chips)
                        <div class="disc-understood">
                            <p class="disc-muted" id="understood-label">What I understood. Remove a part and I will ask again without it.</p>
                            <ul class="disc-chips" aria-labelledby="understood-label">
                                @foreach ($chips as $chip)
                                    <li>
                                        <a class="disc-chip {{ $chip['removed'] ? 'disc-chip--off' : 'disc-chip--applied' }}" href="{{ $chip['href'] }}">
                                            <span>{{ $chip['label'] }}</span>
                                            <x-ds.icon :name="$chip['removed'] ? 'plus' : 'close'" :size="16" />
                                            <span class="ds-visually-hidden">{{ $chip['removed'] ? 'Put back' : 'Remove' }} {{ $chip['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="disc-suggest">
                        <p class="disc-tag disc-tag--ours">Our suggestion</p>
                        <p class="disc-suggest__headline">{{ $answer['suggestion']['headline'] }}</p>
                        <p class="disc-muted">Written by {{ $mode === 'model' ? 'a language model, with every line checked against the catalogue facts before it was accepted' : 'rules, from the facts below, with no model involved' }}.</p>
                        @foreach ($answer['notes'] as $note)<p class="disc-muted">{{ $note }}</p>@endforeach
                    </div>

                    @if ($answer['catalogue']['items'])
                        @php($picks = collect($answer['suggestion']['picks'])->keyBy('product_id'))
                        <ol class="disc-picks">
                            @foreach ($answer['catalogue']['items'] as $item)
                                @php($pick = $picks->get($item['product_id']))
                                <li class="disc-pick">
                                    <div class="disc-pick__media">
                                        @if (($img = $images->get($item['image_id'])) !== null)<img src="{{ $img->url() }}" alt="" width="160" height="160" loading="lazy" decoding="async">@else<span class="disc-pick__noimg"><x-ds.icon name="image" :size="28" /></span>@endif
                                    </div>
                                    <div class="disc-pick__body">
                                        <h3><a href="{{ route('products.show', $item['product_id']) }}">{{ $item['name'] }}</a></h3>
                                        <div class="disc-halves">
                                            <div class="disc-half">
                                                <p class="disc-tag disc-tag--facts">From the catalogue</p>
                                                <table class="disc-facts">
                                                    <caption class="ds-visually-hidden">Facts about {{ $item['name'] }}</caption>
                                                    <tbody>
                                                        @foreach ($item['facts'] as $fact)<tr><th scope="row">{{ $fact['label'] }}</th><td>{{ $fact['value'] }}</td></tr>@endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="disc-half">
                                                <p class="disc-tag disc-tag--ours">Our suggestion</p>
                                                <p>{{ $pick['reason'] ?? 'No suggestion for this product.' }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        <div class="disc-assist__next">
                            <a class="ds-btn ds-btn--quiet" href="{{ route('search', $searchParams) }}">See all {{ number_format($answer['total']) }} as a search, with filters</a>
                            @foreach ($answer['suggestion']['refinements'] as $ref)
                                @if (isset($ref['params']['remove']))
                                    @php($kind = $removeFor($ref['params']['remove']))
                                    @if ($kind)<a class="ds-btn ds-btn--link" href="{{ $askUrl(array_values(array_unique(array_merge($without, [$kind])))) }}#answer">{{ $ref['label'] }}</a>@endif
                                @else
                                    <a class="ds-btn ds-btn--link" href="{{ route('search', array_merge_recursive($searchParams, $ref['params'])) }}">{{ $ref['label'] }}</a>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <div class="disc-assist__empty">
                            <p>Nothing in the catalogue fits, and I only suggest products that exist. A few ways forward:</p>
                            <ul class="disc-relax">
                                @foreach ($answer['suggestion']['refinements'] as $ref)
                                    @php($kind = isset($ref['params']['remove']) ? $removeFor($ref['params']['remove']) : null)
                                    @if ($kind)<li><a href="{{ $askUrl(array_values(array_unique(array_merge($without, [$kind])))) }}#answer">{{ $ref['label'] }}</a></li>@endif
                                @endforeach
                                <li><a href="{{ route('search', ['q' => $text]) }}">Search the shop for these words</a></li>
                                <li><a href="{{ route('categories.index') }}">Browse the categories</a></li>
                            </ul>
                        </div>
                    @endif
                </div>
            </section>
        @endif
    </div>
</x-store-layout>
