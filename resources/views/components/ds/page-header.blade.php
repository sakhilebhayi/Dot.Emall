{{--
  ds.page-header: the page's one <h1>, with an optional lead sentence and actions. Props: title (required), lead=null. Slots: default (extra text under the lead), actions.
  Layouts render this themselves when a page passes <x-slot:heading>; use it directly only in a layout-less view.
--}}
@props(['title', 'lead' => null])
<div {{ $attributes->class('ds-page-header') }}>
    <div class="ds-page-header__text">
        <h1>{{ $title }}</h1>
        @if ($lead)<p class="ds-page-header__lead">{{ $lead }}</p>@endif
        {{ $slot }}
    </div>
    @isset($actions)<div class="ds-page-header__actions">{{ $actions }}</div>@endisset
</div>
