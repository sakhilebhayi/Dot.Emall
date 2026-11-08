{{--
  ds.pagination: numbered pages with Previous and Next, 44px targets, and a plain summary. Props: paginator (required, a LengthAwarePaginator; call ->withQueryString() before passing it).
--}}
@props(['paginator'])
@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage(); $last = $paginator->lastPage();
        $pages = collect([1, $last])->merge(range(max(1, $current - 1), min($last, $current + 1)))->unique()->sort()->values();
    @endphp
    <nav {{ $attributes->class('ds-pager') }} aria-label="Pagination">
        <ul>
            <li>@if ($paginator->onFirstPage())<span class="ds-pager__item is-disabled" aria-disabled="true">Previous</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>@endif</li>
            @php($prev = 0)
            @foreach ($pages as $p)
                @if ($p - $prev > 1)<li><span class="ds-pager__item is-disabled" aria-hidden="true">...</span></li>@endif
                <li>@if ($p === $current)<span class="ds-pager__item" aria-current="page"><span class="ds-visually-hidden">Page </span>{{ $p }}</span>@else<a href="{{ $paginator->url($p) }}"><span class="ds-visually-hidden">Page </span>{{ $p }}</a>@endif</li>
                @php($prev = $p)
            @endforeach
            <li>@if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>@else<span class="ds-pager__item is-disabled" aria-disabled="true">Next</span>@endif</li>
        </ul>
        <p class="ds-pager__summary">Showing {{ number_format($paginator->firstItem()) }} to {{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }}</p>
    </nav>
@endif
