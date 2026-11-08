{{--
  seller.errors: the problems of a form that do not belong to one field (a refused state change, a missing tick), and a count of the rest, at the top of the page.
  Props: only=null (a list of error keys to show; default: every message), title='This did not go through'. Prints nothing when there is nothing to say.
--}}
@props(['only' => null, 'title' => 'This did not go through'])
@php($messages = collect($only === null ? $errors->all() : array_merge(...array_map(fn ($k) => $errors->get($k), $only)))->unique()->values())
@if ($messages->isNotEmpty())
    <x-ds.alert tone="crit" :title="$title" {{ $attributes }}>
        <ul class="sd-errors">@foreach ($messages as $m)<li>{{ $m }}</li>@endforeach</ul>
    </x-ds.alert>
@endif
