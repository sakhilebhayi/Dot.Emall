{{--
  ds.tabs: switch between panels on the same page (details, specifications, reviews). Props: tabs (required: id => label), active=null (default: the first id), label='Sections'.
  Slot: one <x-ds.tab-panel id="..."> per tab. Arrow keys, Home and End move between tabs; the panels switch without a page load. When the sections are separate pages, use ds.subnav links instead.
--}}
@props(['tabs', 'active' => null, 'label' => 'Sections'])
@php($active = $active ?? array_key_first($tabs))
<div {{ $attributes->class('ds-tabs') }}>
    <div class="ds-tabs__list" role="tablist" aria-label="{{ $label }}">
        @foreach ($tabs as $id => $text)
            <button type="button" class="ds-tabs__tab" role="tab" id="tab-{{ $id }}" aria-controls="panel-{{ $id }}" aria-selected="{{ $id === $active ? 'true' : 'false' }}" tabindex="{{ $id === $active ? '0' : '-1' }}">{{ $text }}</button>
        @endforeach
    </div>
    {{ $slot }}
</div>
