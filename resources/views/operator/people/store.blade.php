<x-operator-layout>
    <x-slot:title>{{ $store->name }}</x-slot:title>
    <x-slot:heading>{{ $store->name }}</x-slot:heading>
    <x-slot:lead>Read-only view for support. Opening this page was written to the access log. To decide on the store itself, open its review page.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'People', 'href' => route('operator.people.index')], ['label' => $store->name]]" /></x-slot:breadcrumbs>
    <x-slot:actions><x-ds.button variant="quiet" :href="route('operator.stores.show', $store)">Review and suspend</x-ds.button></x-slot:actions>

    <div class="op-split">
        <div class="ds-stack">
            <section class="op-block" aria-labelledby="o-h">
                <h2 id="o-h">Recent orders with this store</h2>
                @forelse ($orders as $o)<p><a class="op-row-link" href="{{ route('operator.people.order', $o) }}">{{ $o->reference() }}</a> <span class="op-muted">{{ $o->created_at->format('d M Y') }}, {{ ucfirst(str_replace('_', ' ', $o->payment_status->value)) }}</span></p>@empty<p class="op-muted">No orders yet.</p>@endforelse
            </section>
            <section class="op-block" aria-labelledby="r-h">
                <h2 id="r-h">Returns against this seller</h2>
                @forelse ($returns as $r)<p><a class="op-row-link" href="{{ route('operator.returns.show', $r) }}">Return #{{ $r->id }}</a> <span class="op-muted">{{ $r->status->label() }}, {{ $r->created_at->format('d M Y') }}</span></p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
            <section class="op-block" aria-labelledby="rp-h">
                <h2 id="rp-h">Reports about the store</h2>
                @forelse ($reports as $r)<p>{{ str_replace('_', ' ', ucfirst($r->reason)) }} <span class="op-muted">{{ $r->status }}, {{ $r->created_at->format('d M Y') }}</span></p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
            <section class="op-block" aria-labelledby="a-h">
                <h2 id="a-h">Decisions about the store</h2>
                @forelse ($audit as $e)<p class="op-muted"><strong>{{ $e->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($e->action)) }}, <x-op.age :at="$e->created_at" /> ago.@if ($e->reason) {{ $e->reason }}@endif</p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
        </div>
        <aside class="op-side" aria-label="The store">
            <section class="op-block">
                <h2>The store</h2>
                <x-ds.dl>
                    <dt>Store number</dt><dd class="op-num">{{ $store->id }}</dd>
                    <dt>Status</dt><dd>{{ $store->status->label() }}</dd>
                    <dt>Owner</dt><dd><a href="{{ route('operator.people.user', $store->owner_id) }}">{{ $store->owner?->name }}</a>, {{ $store->owner?->email }}</dd>
                    <dt>Contact</dt><dd>{{ $store->contact_email ?: 'None' }}{{ $store->contact_phone ? ' · '.$store->contact_phone : '' }}</dd>
                    <dt>Listings</dt><dd>@forelse ($listings as $s => $n){{ $n }} {{ str_replace('_', ' ', $s) }}{{ ! $loop->last ? ', ' : '' }}@empty None @endforelse</dd>
                    <dt>Ledger</dt>
                    <dd>@forelse ($balance as $currency => $b){{ $currency }}: {{ \App\Support\Shop::money($b['held'], $currency) }} held, {{ \App\Support\Shop::money($b['released'], $currency) }} released, {{ \App\Support\Shop::money($b['commission'], $currency) }} commission{{ ! $loop->last ? '; ' : '' }}@empty Nothing earned yet @endforelse</dd>
                </x-ds.dl>
                @include('operator.partials.ladder', ['trust' => $trust])
            </section>
        </aside>
    </div>
</x-operator-layout>
