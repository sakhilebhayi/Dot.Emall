{{--
  ds.sort-select: "Sort by" select in a GET form that submits when it changes (and has a button when scripts are off).
  Props: options (required: value => label), selected=null (null reads request(name)), name='sort', keep=null (query values to carry along; default: the current query without name and page).
--}}
@props(['options', 'selected' => null, 'name' => 'sort', 'keep' => null])
@php
    $keep = $keep ?? collect(request()->query())->except([$name, 'page'])->all();
    $current = $selected ?? request($name, array_key_first($options));
@endphp
<form method="get" {{ $attributes->class('ds-sort') }}>
    @foreach (\Illuminate\Support\Arr::dot($keep) as $k => $v)
        @php($parts = explode('.', (string) $k))
        <input type="hidden" name="{{ array_shift($parts).(count($parts) ? '['.implode('][', $parts).']' : '') }}" value="{{ $v }}">
    @endforeach
    <label for="sort-{{ $name }}">Sort by</label>
    <div class="ds-select-wrap">
        <select id="sort-{{ $name }}" name="{{ $name }}" class="ds-select" data-autosubmit>
            @foreach ($options as $value => $text)<option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $text }}</option>@endforeach
        </select>
        <x-ds.icon name="chevron-down" :size="18" />
    </div>
    <noscript><button type="submit" class="ds-btn ds-btn--quiet ds-btn--sm">Apply</button></noscript>
</form>
