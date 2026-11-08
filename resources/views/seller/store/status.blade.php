@php
    use App\Enums\StoreStatus;
    $s = $store->status;
    $required = collect($checklist)->where('required', true);
    $requiredDone = $required->where('done', true)->count();
    $doneAll = collect($checklist)->where('done', true)->count();
@endphp
<x-seller-layout>
    <x-slot:title>Store status</x-slot:title>
    <x-slot:heading>{{ $store->name }}</x-slot:heading>
    <x-slot:lead>Where your store stands and what is left to do.</x-slot:lead>
    <x-slot:actions>
        <x-ds.button variant="quiet" :href="route('seller.store.edit')" icon="edit">Edit store profile</x-ds.button>
        @if ($store->is_active && Route::has('stores.show'))<x-ds.button variant="quiet" :href="emall_route('stores.show', $store)" icon="external">View public page</x-ds.button>@endif
    </x-slot:actions>

    <div class="sd-page">
        <x-seller.errors />

        <section aria-labelledby="st-now">
            <h2 id="st-now" class="sd-h2">Status</h2>
            <div class="sd-status-line" style="margin-block-start: var(--s-3);">
                @if ($s === StoreStatus::Active && $store->is_active)<x-ds.badge tone="pos" icon="check-circle">Live</x-ds.badge>
                @elseif ($s === StoreStatus::Active)<x-ds.badge tone="cau" icon="eye-off">Closed by you</x-ds.badge>
                @elseif ($s === StoreStatus::PendingReview)<x-ds.badge tone="info" icon="hourglass">Waiting for review</x-ds.badge>
                @elseif ($s === StoreStatus::Suspended)<x-ds.badge tone="crit" icon="ban">Suspended</x-ds.badge>
                @else<x-ds.badge tone="cau" icon="edit">Draft</x-ds.badge>@endif
                @if ($store->submitted_at && $s === StoreStatus::PendingReview)<span class="sd-muted">Sent for review on {{ $store->submitted_at->format('j M Y') }}.</span>@endif
                @if ($store->reviewed_at && $s === StoreStatus::Active)<span class="sd-muted">Approved by the Emall team on {{ $store->reviewed_at->format('j M Y') }}.</span>@endif
            </div>
            <div class="sd-stack" style="margin-block-start: var(--s-3);">
                @if ($s === StoreStatus::Active && $store->is_active)
                    <p class="sd-muted sd-measure">Your store is open. Shoppers can find it and buy any listing the Emall team has approved.</p>
                @elseif ($s === StoreStatus::Active)
                    <p class="sd-muted sd-measure">You closed your store, so shoppers cannot see it or buy from it. Open it again from your <a href="{{ route('seller.store.edit') }}#open">store profile</a>.</p>
                @elseif ($s === StoreStatus::PendingReview)
                    <p class="sd-muted sd-measure">The Emall team is looking at your store. You can still prepare listings and shipping meanwhile. You will be told here and by notice when there is a decision.</p>
                @elseif ($s === StoreStatus::Suspended)
                    <x-ds.alert tone="crit" title="Why your store is suspended">{{ $store->status_reason ?: 'The Emall team has not recorded a reason. Contact them and ask for one.' }}</x-ds.alert>
                    <p class="sd-muted sd-measure">While a store is suspended it is closed to shoppers, and you cannot reopen it yourself. Orders already placed still have to be handled: they stay on your orders page.</p>
                @else
                    @if ($store->status_reason)<x-ds.alert tone="cau" title="The Emall team sent your store back">{{ $store->status_reason }}</x-ds.alert>@endif
                    <p class="sd-muted sd-measure">Your store is a draft and is not visible to shoppers. Finish the steps marked required, then send it for review.</p>
                @endif
            </div>
        </section>

        <section aria-labelledby="st-list">
            <h2 id="st-list" class="sd-h2" style="margin-block-end: var(--s-2);">Checklist</h2>
            <p class="sd-quiet" style="margin-block-end: var(--s-3);">{{ $doneAll }} of {{ count($checklist) }} done. {{ $requiredDone }} of {{ $required->count() }} of the required steps are done.</p>
            <ol class="sd-check">
                @foreach ($checklist as $item)
                    <li data-done="{{ $item['done'] ? 'true' : 'false' }}">
                        <span class="sd-check__mark">@if ($item['done'])<x-ds.icon name="check" :size="16" />@endif</span>
                        <span class="sd-check__text">
                            <strong>{{ $item['label'] }} <span class="sd-sr">{{ $item['done'] ? ', done' : ', not done' }}</span>@if ($item['required'] && ! $item['done'])<x-ds.badge tone="cau">Required</x-ds.badge>@elseif (! $item['required'] && ! $item['done'])<span class="sd-quiet"> optional</span>@endif</strong>
                            <small>{{ $item['hint'] }}</small>
                        </span>
                        @unless ($item['done'])<a class="ds-btn ds-btn--quiet ds-btn--sm" href="{{ $item['href'] }}">{{ $item['action'] }}</a>@else<a class="ds-btn ds-btn--link" href="{{ $item['href'] }}">Change</a>@endunless
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($s === StoreStatus::Draft)
            <section aria-labelledby="st-submit" class="sd-stack">
                <h2 id="st-submit" class="sd-h2">Send for review</h2>
                @if ($missing)
                    <p class="sd-muted sd-measure">Before you can send your store, finish:</p>
                    <ul class="sd-muted" style="padding-inline-start: 1.25rem;">@foreach ($missing as $m)<li>{{ $m }}</li>@endforeach</ul>
                @else
                    <p class="sd-muted sd-measure">Everything required is in. The Emall team reviews your store, usually before it opens to shoppers; you are told the outcome here.</p>
                @endif
                <form method="POST" action="{{ route('seller.store.submit') }}">@csrf
                    <x-ds.button type="submit" variant="primary" :disabled="(bool) $missing">Send my store for review</x-ds.button>
                </form>
            </section>
        @endif
    </div>
</x-seller-layout>
