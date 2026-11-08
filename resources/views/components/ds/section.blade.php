{{--
  ds.section: a page section with a heading row. Props: title (required, an h2), lead=null, id=null. Slots: default (content), actions (right of the heading, e.g. "See all").
--}}
@props(['title', 'lead' => null, 'id' => null])
<section {{ $attributes->class('ds-section') }} @if ($id) id="{{ $id }}" aria-labelledby="{{ $id }}-h" @endif>
    <div class="ds-section__head">
        <div>
            <h2 @if ($id) id="{{ $id }}-h" @endif>{{ $title }}</h2>
            @if ($lead)<p class="ds-section__lead">{{ $lead }}</p>@endif
        </div>
        @isset($actions)<div>{{ $actions }}</div>@endisset
    </div>
    {{ $slot }}
</section>
