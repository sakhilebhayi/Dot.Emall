<x-operator-layout>
    <x-slot:title>{{ $store->name }}</x-slot:title>
    <x-slot:heading>{{ $store->name }}</x-slot:heading>
    <x-slot:lead>Store review. What the seller told us, what we recorded, and your decision.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Stores', 'href' => route('operator.stores.index')], ['label' => $store->name]]" /></x-slot:breadcrumbs>
    <x-slot:actions><x-ds.badge :tone="['active' => 'pos', 'pending_review' => 'cau', 'suspended' => 'crit', 'draft' => 'neutral'][$store->status->value] ?? 'neutral'">{{ $store->status->label() }}</x-ds.badge></x-slot:actions>

    <div class="op-split">
        <div class="ds-stack">
            @if ($store->status_reason)<p class="op-quote">{{ $store->status_reason }}<small>Shown to the seller as the reason{{ $store->reviewer ? ' (written by '.$store->reviewer->name.')' : '' }}</small></p>@endif
            @if ($reports > 0)<x-ds.alert tone="crit" title="{{ $reports }} open {{ \Illuminate\Support\Str::plural('report', $reports) }}"><a href="{{ route('operator.reports.show', ['type' => 'store', 'id' => $store->id]) }}">Read the reports</a></x-ds.alert>@endif

            <section class="op-block" aria-labelledby="told-h">
                <h2 id="told-h">What the seller told us</h2>
                <p class="op-muted">Typed by the seller. Numbers here are claims; we do not check them.</p>
                <x-ds.dl>
                    <dt>Trading name</dt><dd>{{ $store->name }}</dd>
                    <dt>Legal name</dt><dd>{{ $store->legal_name ?: 'Not given' }}</dd>
                    <dt>Contact</dt><dd>{{ $store->contact_email ?: 'No e-mail' }}{{ $store->contact_phone ? ' · '.$store->contact_phone : '' }}</dd>
                    <dt>Ships from</dt><dd>{{ trim($store->city.', '.$store->region, ', ') ?: 'Not given' }}</dd>
                    <dt>Registration number</dt><dd>{{ $store->registration_number ? $store->registration_number.' (claimed)' : 'Not given' }}</dd>
                    <dt>VAT</dt><dd>{{ $store->vat_registered ? ($store->vat_number ? $store->vat_number.' (claimed)' : 'Registered, no number given') : 'Not registered' }}</dd>
                </x-ds.dl>
                <h3>Description</h3>
                <p class="op-prose">{{ $store->description ?: 'No description.' }}</p>
                <h3>Return policy</h3>
                <p class="op-prose">{{ $store->return_policy ?: 'Not written.' }}</p>
                <h3>Shipping policy</h3>
                <p class="op-prose">{{ $store->shipping_policy ?: 'Not written.' }}</p>
            </section>

            <section class="op-block" aria-labelledby="decide-h">
                <h2 id="decide-h">Your decision</h2>
                @foreach ($errors->getBag('store-'.$store->id)->get('status') as $message)<x-ds.alert tone="crit">{{ $message }}</x-ds.alert>@endforeach
                <div class="op-decisions">
                    @if ($store->status === \App\Enums\StoreStatus::PendingReview)
                        <form method="POST" action="{{ route('operator.stores.approve', $store) }}">@csrf<x-ds.button type="submit">Approve store</x-ds.button></form>
                        <x-op.decide :bag="'store-'.$store->id" :action="route('operator.stores.reject', $store)" summary="Send back with a reason" submit="Send back and tell the seller" tone="quiet" label="What the seller must change" hint="They read this, fix it and submit again." />
                    @endif
                    @if ($store->status === \App\Enums\StoreStatus::Suspended)
                        <x-op.decide :bag="'store-'.$store->id" :action="route('operator.stores.reinstate', $store)" summary="Reinstate store" tone="primary" label="Note (optional)" :required="false" hint="Kept in the audit trail." />
                    @else
                        <x-op.decide :bag="'store-suspend-'.$store->id" :action="route('operator.stores.suspend', $store)" summary="Suspend store" submit="Suspend and tell the seller" tone="danger" label="Why the store is suspended" hint="The store closes to shoppers at once. The seller reads this reason." />
                    @endif
                </div>
            </section>
        </div>

        <aside class="op-side" aria-label="What we recorded">
            <section class="op-block">
                <h2>What we recorded</h2>
                <p class="op-muted">Owner {{ $store->owner?->name }} · opened <x-op.age :at="$store->created_at" /> ago · submitted {{ $store->submitted_at ? '' : 'never' }}<x-op.age :at="$store->submitted_at" none="" /></p>
                @include('operator.partials.ladder', ['trust' => $trust])
                @if ($missing)<p class="op-muted">Still missing for submission: {{ implode(' ', $missing) }}</p>@endif
            </section>
            <section class="op-block">
                <h2>Listings</h2>
                @if ($listings->isEmpty())<p class="op-muted">No listings yet.</p>@else
                    <x-ds.dl>@foreach ($listings as $s => $n)<dt>{{ ucfirst(str_replace('_', ' ', $s)) }}</dt><dd class="op-num">{{ $n }}</dd>@endforeach</x-ds.dl>
                @endif
                <a href="{{ route('operator.people.store', $store) }}">Open the support view</a>
            </section>
            <section class="op-block">
                <h2>History</h2>
                @forelse ($history as $entry)
                    <p class="op-muted"><strong>{{ $entry->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($entry->action)) }}, <x-op.age :at="$entry->created_at" /> ago.@if ($entry->reason)<br>{{ $entry->reason }}@endif</p>
                @empty<p class="op-muted">No decision recorded yet.</p>@endforelse
            </section>
        </aside>
    </div>
</x-operator-layout>
