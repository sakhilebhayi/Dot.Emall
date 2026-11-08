{{--
  ds.facet-group: one collapsible filter. A native <details>, so it opens and closes with the keyboard and is announced.
  Props: title (required), name (required, the query key), type='checkbox' | 'radio' | 'range', options=[] (list of ['value' =>, 'label' =>, 'count' => null, 'checked' => false]),
         open=true, min=null / max=null (range: the current values), unit='R' (range prefix; the inputs are plain numbers, a fallback that needs no slider),
         limit=null (show this many options, plus any that are ticked, and put the rest behind "Show N more": a native <details>, so it needs no script and nothing is clipped or hidden in a scroll box).
  Checkbox groups submit name[]=value; ranges submit name_min and name_max.
--}}
@props(['title', 'name', 'type' => 'checkbox', 'options' => [], 'open' => true, 'min' => null, 'max' => null, 'unit' => 'R', 'limit' => null])
@php
    $options = array_values($options);
    $shown = $options;
    $more = [];
    if ($limit !== null && count($options) > $limit + 1) {
        $shown = []; $more = [];
        foreach ($options as $i => $o) { ($i < $limit || ! empty($o['checked'])) ? $shown[] = $o : $more[] = $o; }
        if (count($more) < 2) { $shown = $options; $more = []; }
    }
@endphp
<details {{ $attributes->class('ds-facet') }} @if ($open) open @endif>
    <summary>{{ $title }}<x-ds.icon name="chevron-down" :size="18" /></summary>
    <div class="ds-facet__body">
        @if ($type === 'range')
            <div class="ds-facet__range">
                <div class="ds-field"><label class="ds-field__label" for="{{ $name }}_min">Min {{ $unit }}</label><input class="ds-input" type="number" inputmode="numeric" min="0" id="{{ $name }}_min" name="{{ $name }}_min" value="{{ $min }}"></div>
                <span aria-hidden="true">to</span>
                <div class="ds-field"><label class="ds-field__label" for="{{ $name }}_max">Max {{ $unit }}</label><input class="ds-input" type="number" inputmode="numeric" min="0" id="{{ $name }}_max" name="{{ $name }}_max" value="{{ $max }}"></div>
            </div>
        @else
            @foreach ($shown as $o)
                @include('components.ds._facet-row', ['o' => $o, 'type' => $type, 'name' => $name])
            @endforeach
            @if ($more)
                <details class="ds-facet__more">
                    <summary><span class="ds-facet__more-open">Show {{ count($more) }} more</span><span class="ds-facet__more-close">Show fewer</span></summary>
                    @foreach ($more as $o)
                        @include('components.ds._facet-row', ['o' => $o, 'type' => $type, 'name' => $name])
                    @endforeach
                </details>
            @endif
        @endif
    </div>
</details>
