{{--
  dx.foot (R-51): the status line under a dashboard. Live counts only, each its own span (no separator characters). Props: items (list of strings), sample=null (a label for seeded demo content; null on a real shop).
--}}
@props(['items'])
<div class="dx-foot">@foreach ($items as $item)<span>{{ $item }}</span>@endforeach{{ $slot }}</div>
