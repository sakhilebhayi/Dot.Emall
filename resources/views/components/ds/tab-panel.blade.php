{{--
  ds.tab-panel: the content of one tab. Props: id (required, matches the key in ds.tabs). Panels start visible so nothing is lost without script; app.js hides the inactive ones on load.
--}}
@props(['id'])
<div class="ds-tabs__panel" role="tabpanel" id="panel-{{ $id }}" aria-labelledby="tab-{{ $id }}" tabindex="0" {{ $attributes }}>{{ $slot }}</div>
