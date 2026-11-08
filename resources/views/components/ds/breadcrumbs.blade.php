{{--
  ds.breadcrumbs: the trail to this page. Props: items (required: list of ['label' =>, 'href' => null]); the last item is the current page and is not a link.
--}}
@props(['items'])
<nav {{ $attributes->class('ds-crumbs') }} aria-label="Breadcrumb">
    <ol>
        @foreach ($items as $i => $item)
            <li>@if ($loop->last || empty($item['href']))<span aria-current="page">{{ $item['label'] }}</span>@else<a href="{{ $item['href'] }}">{{ $item['label'] }}</a>@endif</li>
        @endforeach
    </ol>
</nav>
