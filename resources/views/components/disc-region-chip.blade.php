{{--
  disc-region-chip: "Delivering to Gauteng" with a province chooser. Put it in the layout's `location` slot: <x-slot:location><x-disc-region-chip /></x-slot:location>.
  The choice is a province only (nine to pick from, no precise location): it is kept in the session and in a one-year cookie by the region.update route, and read anywhere with
  App\Support\ShopperRegion::current(). It feeds local discovery on the home page and in search and is the shopper's region for delivery estimates until they enter an address.
  Props: none. Needs: the route region.update (registered with the discovery pages). Renders nothing when that route is missing.
--}}
@php
    $current = \App\Support\ShopperRegion::current();
    $back = request()->getRequestUri();
@endphp
@if (\App\Support\Shell::routeExists('region.update'))
    <button type="button" class="disc-region" data-dialog-open="region-dialog" aria-haspopup="dialog">
        <x-ds.icon name="pin" :size="16" />
        <span>@if ($current)Delivering to <strong>{{ $current }}</strong>@else Choose your province @endif</span>
    </button>
    <x-ds.dialog id="region-dialog" title="Where do you shop from?">
        <form method="POST" action="{{ route('region.update') }}" class="disc-region__form" id="region-form">
            @csrf
            <input type="hidden" name="return" value="{{ $back }}">
            <p class="disc-muted">Pick your province to see sellers near you first and to start delivery estimates. We store only the province, in this browser, and never ask for your location.</p>
            <fieldset class="disc-region__list">
                <legend class="ds-visually-hidden">Province</legend>
                <label class="ds-facet__row"><input type="radio" name="province" value="" @checked(! $current)><span>Not set</span></label>
                @foreach (\App\Support\ShopperRegion::provinces() as $province)
                    <label class="ds-facet__row"><input type="radio" name="province" value="{{ $province }}" @checked($current === $province)><span>{{ $province }}</span></label>
                @endforeach
            </fieldset>
        </form>
        <x-slot:footer>
            <x-ds.button variant="quiet" data-dialog-close>Cancel</x-ds.button>
            <x-ds.button type="submit" form="region-form">Save province</x-ds.button>
        </x-slot:footer>
    </x-ds.dialog>
@endif
