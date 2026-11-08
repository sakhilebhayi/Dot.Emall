{{--
  ds.option-picker: choose one option (size, colour, pack). A radio group, so it works without script and with a keyboard.
  Props: name (required), label (required, the legend, e.g. "Size"), options (required: list of ['value' =>, 'label' =>, 'disabled' => false, 'swatch' => '#rrggbb' (colour data from the catalogue, optional), 'note' => 'Sold out']),
         selected=null, type='chips' | 'swatch', required=false.
  Unavailable options stay in the list: dashed, crossed, disabled, and their accessible name says so ("Large, unavailable"). Swatches always carry a text name for screen readers.
--}}
@props(['name', 'label', 'options' => [], 'selected' => null, 'type' => 'chips', 'required' => false])
@php($current = collect($options)->firstWhere('value', $selected))
<fieldset {{ $attributes->class('ds-options') }}>
    <legend>{{ $label }}@if ($current)<span class="ds-options__current">{{ $current['label'] }}</span>@endif</legend>
    <div class="ds-options__list">
        @foreach ($options as $i => $o)
            @php($off = (bool) ($o['disabled'] ?? false))
            <label class="ds-option {{ $type === 'swatch' ? 'ds-option--swatch' : '' }}">
                <input type="radio" name="{{ $name }}" value="{{ $o['value'] }}" @checked((string) $selected === (string) $o['value']) @disabled($off) @if ($required && $i === 0) required @endif>
                <span class="ds-option__face">
                    @if ($type === 'swatch')<span class="ds-swatch" @if (! empty($o['swatch'])) style="--swatch: {{ $o['swatch'] }}" @endif></span>@else{{ $o['label'] }}@endif
                    <span class="ds-visually-hidden">@if ($type === 'swatch'){{ $o['label'] }}@endif{{ $off ? ', '.($o['note'] ?? 'unavailable') : '' }}</span>
                </span>
            </label>
        @endforeach
    </div>
</fieldset>
