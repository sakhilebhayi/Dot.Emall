{{--
  ds.search-field: the shop's search, an ARIA combobox with a suggestion listbox.
  Props: action (required, the results URL, e.g. emall_route('search')), name='q', value=null (null reads request('q')), id='site-search', placeholder='Search products, brands and stores',
         label='Search products', endpoint=null (a URL that answers GET ?q= with {"suggestions":[{"label":"..","url":"..","meta":".."}]}; the script debounces and renders them).
  Slot suggestions: server-rendered options instead of an endpoint: <li class="ds-search__opt" role="option" id=".." aria-selected="false"><a href="..">Label</a></li>.
  Keyboard: Down and Up move through suggestions, Enter opens one, Esc closes. With no suggestions it is a plain search form.
--}}
@props(['action', 'name' => 'q', 'value' => null, 'id' => 'site-search', 'placeholder' => 'Search products, brands and stores', 'label' => 'Search products', 'endpoint' => null])
<div {{ $attributes->class('ds-search') }} data-search id="{{ $id }}" @if ($endpoint) data-endpoint="{{ $endpoint }}" @endif>
    <form role="search" method="get" action="{{ $action }}" class="ds-search__form">
        <label class="ds-visually-hidden" for="{{ $id }}-input">{{ $label }}</label>
        <input class="ds-search__input" id="{{ $id }}-input" type="search" name="{{ $name }}" value="{{ $value ?? request($name) }}" placeholder="{{ $placeholder }}" autocomplete="off" enterkeyhint="search"
               role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="{{ $id }}-list" aria-haspopup="listbox">
        <button type="submit" class="ds-search__submit" aria-label="Search"><x-ds.icon name="search" :size="20" /></button>
    </form>
    <div class="ds-search__panel" hidden>
        <ul role="listbox" id="{{ $id }}-list" aria-label="Suggestions">{{ $suggestions ?? '' }}</ul>
    </div>
</div>
