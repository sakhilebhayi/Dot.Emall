{{--
  disc-rail: a row of products with the reason it is shown. One partial for every recommendation block: "Recently viewed", "Because you viewed X", "Bought together", "Similar",
  "Trending" and the homepage shelves.
  Props: set=null (an App\Services\Discovery\RecommendationSet, or any object with title, basis and items), or title + basis + items given directly;
         items=[] (list of App\Services\Discovery\RecommendedItem: product, basis, support), title=null, basis=null (the sentence that says what the block is made from),
         id=null (anchor id), level=2 (heading level of the title, 2 to 4), eager=0 (how many first cards load eagerly, for a rail above the fold),
         layout='strip' (scrolling strip) | 'grid', link=null (URL of "See all"), linkLabel='See all'.
  Every item carries its own basis ("Bought together in 5 orders"), printed under its card, so the real number is always visible. Renders nothing when there are no items.
  W4 adopts it on the product page: <x-disc-rail :set="$set" /> for each set from Recommender::forProduct().
--}}
@props(['set' => null, 'items' => [], 'title' => null, 'basis' => null, 'id' => null, 'level' => 2, 'eager' => 0, 'layout' => 'strip', 'link' => null, 'linkLabel' => 'See all'])
@php
    $items = $set ? $set->items : $items;
    $title = $set ? $set->title : $title;
    $basis = $set ? $set->basis : $basis;
    $id = $id ?? ($set ? 'rail-'.$set->key : null);
    $h = 'h'.max(2, min(4, (int) $level));
    $mine = \App\Support\ShopperRegion::current();
    $regions = $mine && count($items) ? app(\App\Contracts\CatalogueSource::class)->storeRegions(array_values(array_unique(array_map(fn ($i) => (int) $i->product->store_id, $items)))) : [];
@endphp
@if (count($items))
    <section {{ $attributes->class('disc-rail') }} @if ($id) id="{{ $id }}" aria-labelledby="{{ $id }}-h" @endif>
        <div class="disc-rail__head">
            <div>
                <{{ $h }} class="disc-rail__title" @if ($id) id="{{ $id }}-h" @endif>{{ $title }}</{{ $h }}>
                @if ($basis)<p class="disc-rail__basis">{{ $basis }}</p>@endif
            </div>
            @if ($link)<a class="disc-rail__all" href="{{ $link }}">{{ $linkLabel }}<span class="ds-visually-hidden"> in {{ $title }}</span></a>@endif
        </div>
        <ul class="disc-rail__list disc-rail__list--{{ $layout }}">
            @foreach ($items as $item)
                <li>
                    <x-disc-card :product="$item->product" :why="$item->basis" :region="$regions[(int) $item->product->store_id] ?? null" :mine="$mine" :eager="$loop->index < (int) $eager" />
                </li>
            @endforeach
        </ul>
    </section>
@endif
