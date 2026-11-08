{{--
  The shop's search and listing page. One view for /search, /products and /categories/{slug} (Discovery\SearchController): the facets, the sort, the chips of what was applied, the grid or list, the
  honest empty page. State is the query string; nothing here needs a script except the phone filter sheet and the suggestions.
--}}
@php
    $isCategory = $category !== null;
    $q = $search->text;
    $heading = $isCategory ? $category['name'] : ($q !== '' ? 'Results for "'.$q.'"' : 'All products');
    $page = $products->currentPage();
    $count = $products->total();
    $activeCount = count($chips);
    $listMode = $view === 'list';
    $resultsClass = 'disc-results disc-results--'.$view.($view === 'list' ? '' : ' ds-grid');
    $mineRegion = $mine;
@endphp
<x-store-layout :robots="$keepRobots ? null : 'noindex, follow'">
    <x-slot:title>{!! $heading !!}{{ $page > 1 ? ', page '.$page : '' }}</x-slot:title>
    <x-slot:description>{{ $isCategory ? ($categoryNode && $count ? $category['name'].': '.number_format($count).' products from South African sellers, each with its price, delivery and total on one label.' : 'Browse '.$category['name'].' on Dot.Emall.') : 'Search and browse products from South African sellers on Dot.Emall. Every product shows its price, delivery and total before you pay.' }}</x-slot:description>
    <x-slot:location><x-disc-region-chip /></x-slot:location>
    <x-slot:breadcrumbs>
        <x-ds.breadcrumbs :items="$isCategory ? $breadcrumbs : [['label' => 'Home', 'href' => url('/')], ['label' => $q !== '' ? 'Search' : 'Products']]" />
    </x-slot:breadcrumbs>
    {{-- The layout hands the heading to ds.page-header, which escapes it: raw here, so a quote or an ampersand is escaped once, not twice. --}}
    <x-slot:heading>{!! $heading !!}</x-slot:heading>
    <x-slot:lead>
        @if ($count === 0){{ $q !== '' || $activeCount ? 'No products match this search.' : 'Nothing is listed here right now.' }}@else{{ number_format($count) }} {{ \Illuminate\Support\Str::plural('product', $count) }}{{ $isCategory ? ' in '.$category['name'] : '' }}, from approved listings in open stores.@endif
    </x-slot:lead>

    @if ($isCategory)
        <div class="disc-category">
            @if (! empty($categoryDescription ?? null))<p class="disc-intro">{{ $categoryDescription }}</p>@endif
            @if ($categoryNode && count(array_filter($categoryNode['children'], fn ($c) => $c['rollup_count'] > 0)))
                <nav aria-labelledby="subcats-h" class="disc-subcats">
                    <h2 id="subcats-h" class="disc-subhead">Browse {{ $category['name'] }}</h2>
                    <ul class="disc-subcats__list">
                        @foreach (array_filter($categoryNode['children'], fn ($c) => $c['rollup_count'] > 0) as $child)
                            <li><a href="{{ route('categories.show', $child['slug']) }}"><span>{{ $child['name'] }}</span><span class="disc-count">{{ number_format($child['rollup_count']) }}</span></a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif
            @if (count($topBrands) > 1)
                <div class="disc-brands">
                    <h2 class="disc-subhead">Top brands in {{ $category['name'] }}</h2>
                    <ul class="disc-chips">
                        @foreach ($topBrands as $brand)
                            <li><a class="disc-chip" href="{{ $brand['href'] }}" @if ($brand['selected']) aria-current="true" @endif>{{ $brand['label'] }}<span class="disc-count">{{ number_format($brand['count']) }}</span></a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    <div class="disc-shop">
        <aside class="disc-shop__panel" id="filters-panel" aria-label="Filters">
            <h2 class="disc-panel-title">Filters</h2>
            @if ($result->total > 0 || $activeCount > 0)
                <x-disc-filters :search="$search" :result="$result" :links="$links" uid="panel" :categories="$facetCategories" :mine="$mineRegion" />
            @else
                <p class="disc-muted">Filters appear when there are results to narrow.</p>
            @endif
        </aside>

        <div class="disc-shop__main">
            <div class="disc-toolbar">
                <p class="disc-toolbar__count" aria-live="polite">
                    @if ($count > 0)Showing <strong>{{ number_format($products->firstItem()) }} to {{ number_format($products->lastItem()) }}</strong> of {{ number_format($count) }}@else No products @endif
                </p>
                <div class="disc-toolbar__tools">
                    @if ($result->total > 0 || $activeCount > 0)
                        <button type="button" class="ds-btn ds-btn--quiet disc-filters-open" data-dialog-open="filters-sheet"><x-ds.icon name="filter" :size="18" /><span>Filters{{ $activeCount ? ' ('.$activeCount.')' : '' }}</span></button>
                    @endif
                    <x-ds.sort-select :options="$sorts" :selected="$search->sort" />
                    <div class="disc-view" role="group" aria-label="Layout">
                        <a class="ds-icon-btn" href="{{ $links->view('grid') }}" aria-label="Grid" title="Grid" @if ($view === 'grid') aria-current="true" @endif><x-ds.icon name="grid" :size="20" /></a>
                        <a class="ds-icon-btn" href="{{ $links->view('list') }}" aria-label="List" title="List" @if ($view === 'list') aria-current="true" @endif><x-ds.icon name="list" :size="20" /></a>
                    </div>
                </div>
            </div>

            @if ($chips)
                <ul class="disc-chips disc-chips--applied" aria-label="Filters applied">
                    @foreach ($chips as $chip)
                        <li>
                            <a class="disc-chip disc-chip--applied" href="{{ $chip['remove'] }}">
                                <span>{{ $chip['label'] }}@if ($chip['inferred'])<small> from your words</small>@endif</span>
                                <x-ds.icon name="close" :size="16" />
                                <span class="ds-visually-hidden">{{ $chip['inferred'] ? 'Search the words only, without' : 'Remove filter' }} {{ $chip['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                    @if (count($chips) > 1)
                        <li><a class="disc-chip-clear" href="{{ $links->url($search->with(['categoryId' => $isCategory ? $search->categoryId : null, 'brands' => [], 'priceMin' => null, 'priceMax' => null, 'inStock' => false, 'minRating' => null, 'storeIds' => [], 'regions' => [], 'attributes' => [], 'page' => 1]), null, true) }}">Clear all</a></li>
                    @endif
                </ul>
            @endif

            @if ($result->didYouMean || $result->correctedFrom || ($count > 0 && ($result->notes || $result->sortNote)))
                <div class="disc-notes">
                    @if ($result->didYouMean)<p>Did you mean <a href="{{ $links->url($search->with(['text' => $result->didYouMean, 'page' => 1])) }}"><strong>{{ $result->didYouMean }}</strong></a>?</p>@endif
                    @if ($count > 0)@foreach ($result->notes as $note)<p>{{ $note }}</p>@endforeach @endif
                    @if ($result->correctedFrom)<p><a href="{{ $links->url($search->with(['text' => $result->correctedFrom, 'page' => 1]), null, true) }}">Search for "{{ $result->correctedFrom }}" exactly as typed instead</a></p>@endif
                    @if ($result->sortNote)<p>{{ $result->sortNote }}</p>@endif
                </div>
            @endif

            @if ($localCount)
                <p class="disc-local"><x-ds.icon name="pin" :size="16" /><a href="{{ $links->with(['regions' => [$localCount['region']]]) }}">Only sellers in {{ $localCount['region'] }} ({{ number_format($localCount['count']) }})</a></p>
            @endif

            @if ($products->count())
                <div class="{{ $resultsClass }}">
                    @foreach ($result->items as $hit)
                        <x-disc-card :product="$hit->product" :view="$listMode ? 'row' : 'grid'" :why="$hit->why() ?: null" :region="$regions[(int) $hit->product->store_id] ?? null" :mine="$mineRegion" :eager="$loop->index < 4" />
                    @endforeach
                </div>
                <div class="disc-pager"><x-ds.pagination :paginator="$products" /></div>
            @else
                @include('discover.zero', ['zero' => $zero])
            @endif

            @if ($canSave)
                <div class="disc-save">
                    <x-ds.button variant="quiet" icon="bookmark" data-dialog-open="save-search-dialog">Save this search</x-ds.button>
                    <p class="disc-muted">Get the new matches in your account. Saved searches are optional and e-mail is off until you turn it on.</p>
                </div>
            @endif
        </div>
    </div>

    {{-- The phone filter sheet: the same form in a native dialog (bottom sheet on phones). --}}
    @if ($result->total > 0 || $activeCount > 0)
        <x-ds.dialog id="filters-sheet" title="Filters" class="disc-sheet">
            <x-disc-filters :search="$search" :result="$result" :links="$links" uid="sheet" :categories="$facetCategories" :mine="$mineRegion" />
        </x-ds.dialog>
    @endif

    @if ($canSave && $signedIn)
        <x-ds.dialog id="save-search-dialog" title="Save this search">
            <form method="POST" action="{{ route('account.saved-searches.store') }}" id="save-search-form" class="ds-stack">
                @csrf
                @foreach (\Illuminate\Support\Arr::dot($links->carried()) as $k => $v)
                    @continue(in_array($k, ['view', 'literal', 'sort'], true) || str_starts_with($k, 'page'))
                    @php($parts = explode('.', (string) $k))
                    <input type="hidden" name="search[{{ array_shift($parts) }}]{{ count($parts) ? '['.implode('][', $parts).']' : '' }}" value="{{ $v }}">
                @endforeach
                @if ($isCategory)<input type="hidden" name="search[category]" value="{{ $category['id'] }}">@endif
                <x-ds.input label="Name" name="name" id="save-search-name" :value="$isCategory ? $category['name'] : ($q !== '' ? $q : 'My search')" required maxlength="80" />
                <x-ds.select label="E-mail me new matches" name="notify_frequency" id="save-search-frequency" :options="['off' => 'No e-mail (see them in my account)', 'daily' => 'Daily, only when there is something new', 'weekly' => 'Weekly, only when there is something new']" selected="off" />
            </form>
            <x-slot:footer>
                <x-ds.button variant="quiet" data-dialog-close>Cancel</x-ds.button>
                <x-ds.button type="submit" form="save-search-form">Save search</x-ds.button>
            </x-slot:footer>
        </x-ds.dialog>
    @elseif ($canSave)
        <x-ds.dialog id="save-search-dialog" title="Save this search">
            <p>Saved searches live in your Emall account, and accounts come from InfoDot. Sign in with InfoDot and you can save this search and see what is new on it.</p>
            <x-slot:footer>
                <x-ds.button variant="quiet" data-dialog-close>Not now</x-ds.button>
                <x-ds.button :href="route('login')">Sign in with InfoDot</x-ds.button>
            </x-slot:footer>
        </x-ds.dialog>
    @endif
</x-store-layout>
