{{--
  disc-collection-panel: one collection as an editorial panel: its title, a sentence, its dates as recorded, and a mosaic of the real photographs of what is in it.
  Props: collection (App\Models\Collection), preview (a collection of Product with images), basis=null (one honest sentence on how it is made), large=false (the lead panel, with more photographs).
--}}
@props(['collection', 'preview', 'basis' => null, 'large' => false])
@php($shots = $preview->take($large ? 4 : 3)->filter(fn ($p) => $p->images->isNotEmpty()))
<article {{ $attributes->class(['disc-panel-c', 'disc-panel-c--large' => $large]) }}>
    <div class="disc-panel-c__text">
        <h3 class="disc-panel-c__title"><a href="{{ route('collections.show', $collection->slug) }}">{{ $collection->title }}</a></h3>
        @if ($collection->description)<p>{{ \Illuminate\Support\Str::limit($collection->description, $large ? 220 : 120) }}</p>@endif
        <p class="disc-muted">{{ \App\Support\CollectionText::window($collection) }}.@if ($basis) {{ $basis }}.@endif</p>
    </div>
    @if ($shots->isNotEmpty())
        <ul class="disc-mosaic disc-mosaic--{{ $shots->count() }}" aria-hidden="true">
            @foreach ($shots as $p)<li><img src="{{ $p->images->first()->url() }}" alt="" width="240" height="240" loading="lazy" decoding="async"></li>@endforeach
        </ul>
    @endif
</article>
