{{-- One option of a facet group (ds.facet-group). Props: o (value, label, count?, checked?), type, name. --}}
<label class="ds-facet__row">
    <input type="{{ $type }}" name="{{ $name }}{{ $type === 'checkbox' ? '[]' : '' }}" value="{{ $o['value'] }}" @checked(! empty($o['checked']))>
    <span>{{ $o['label'] }}</span>
    @if (isset($o['count']))<span class="ds-facet__count"><span class="ds-visually-hidden">, </span>{{ number_format((int) $o['count']) }}</span>@endif
</label>
