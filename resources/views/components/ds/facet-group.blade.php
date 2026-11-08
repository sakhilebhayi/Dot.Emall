{{--
  ds.facet-group: one collapsible filter. A native <details>, so it opens and closes with the keyboard and is announced.
  Props: title (required), name (required, the query key), type='checkbox' | 'radio' | 'range', options=[] (list of ['value' =>, 'label' =>, 'count' => null, 'checked' => false]),
         open=true, min=null / max=null (range: the current values), unit='R' (range prefix; the inputs are plain numbers, a fallback that needs no slider).
  Checkbox groups submit name[]=value; ranges submit name_min and name_max.
--}}
@props(['title', 'name', 'type' => 'checkbox', 'options' => [], 'open' => true, 'min' => null, 'max' => null, 'unit' => 'R'])
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
            @foreach ($options as $o)
                <label class="ds-facet__row">
                    <input type="{{ $type }}" name="{{ $name }}{{ $type === 'checkbox' ? '[]' : '' }}" value="{{ $o['value'] }}" @checked(! empty($o['checked']))>
                    <span>{{ $o['label'] }}</span>
                    @if (isset($o['count']))<span class="ds-facet__count"><span class="ds-visually-hidden">, </span>{{ number_format((int) $o['count']) }}</span>@endif
                </label>
            @endforeach
        @endif
    </div>
</details>
