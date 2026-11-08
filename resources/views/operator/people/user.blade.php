<x-operator-layout>
    <x-slot:title>{{ $user->name }}</x-slot:title>
    <x-slot:heading>{{ $user->name }}</x-slot:heading>
    <x-slot:lead>Read-only view for support. Opening this page was written to the access log.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'People', 'href' => route('operator.people.index')], ['label' => $user->name]]" /></x-slot:breadcrumbs>

    <div class="op-split">
        <div class="ds-stack">
            <section class="op-block" aria-labelledby="orders-h">
                <h2 id="orders-h">Orders ({{ $order_total }})</h2>
                @if ($orders->isEmpty())<p class="op-muted">No orders.</p>@else
                    <x-ds.table caption="Orders, newest first">
                        <x-slot:head><tr><th scope="col">Order</th><th scope="col">Placed</th><th scope="col">Status</th><th scope="col">Payment</th><th scope="col" class="is-num">Total</th></tr></x-slot:head>
                        @foreach ($orders as $o)
                            <tr>
                                <td data-label="Order"><a class="op-row-link" href="{{ route('operator.people.order', $o) }}">{{ $o->reference() }}</a></td>
                                <td data-label="Placed">{{ $o->created_at->format('d M Y') }}</td>
                                <td data-label="Status">{{ ucfirst($o->status->label()) }}</td>
                                <td data-label="Payment">{{ ucfirst(str_replace('_', ' ', $o->payment_status->value)) }}</td>
                                <td data-label="Total" class="is-num">{{ $o->money($o->totalCents()) }}</td>
                            </tr>
                        @endforeach
                    </x-ds.table>
                @endif
            </section>
            <section class="op-block" aria-labelledby="ret-h">
                <h2 id="ret-h">Returns they asked for ({{ $returns->count() }})</h2>
                @forelse ($returns as $r)<p><a class="op-row-link" href="{{ route('operator.returns.show', $r) }}">Return #{{ $r->id }}</a> <span class="op-muted">{{ $r->status->label() }}, {{ $r->created_at->format('d M Y') }}</span></p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
            <section class="op-block" aria-labelledby="pay-h">
                <h2 id="pay-h">Payments ({{ $payments->count() }})</h2>
                @forelse ($payments as $p)<p><span class="op-code">{{ $p->reference }}</span> <span class="op-muted">{{ $p->status->value }}, {{ \App\Support\Shop::money($p->amount_cents, $p->currency) }}, {{ $p->created_at->format('d M Y') }}</span></p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
            <section class="op-block" aria-labelledby="rep-h">
                <h2 id="rep-h">Reports they filed ({{ $reports_filed->count() }})</h2>
                @forelse ($reports_filed as $r)<p><a class="op-row-link" href="{{ route('operator.reports.show', ['type' => $r->reportable_type, 'id' => $r->reportable_id]) }}">{{ ucfirst($r->reportable_type) }} #{{ $r->reportable_id }}</a> <span class="op-muted">{{ str_replace('_', ' ', $r->reason) }}, {{ $r->status }}</span></p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
        </div>
        <aside class="op-side" aria-label="Account">
            <section class="op-block">
                <h2>Account</h2>
                <x-ds.dl>
                    <dt>Person number</dt><dd class="op-num">{{ $user->id }}</dd>
                    <dt>E-mail</dt><dd>{{ $user->email }} {{ $user->email_verified_at ? '(verified)' : '(not verified)' }}</dd>
                    <dt>Joined</dt><dd>{{ $user->created_at?->format('d M Y') }}</dd>
                    <dt>Signed in through InfoDot</dt><dd>{{ $user->infodot_signed_in_at ? $user->infodot_signed_in_at->format('d M Y') : 'Never' }}</dd>
                    <dt>Operator</dt><dd>{{ $user->is_platform_operator ? 'Yes' : 'No' }}</dd>
                    <dt>Store</dt><dd>@if ($store)<a href="{{ route('operator.people.store', $store) }}">{{ $store->name }}</a>@else None @endif</dd>
                </x-ds.dl>
                @if ($reports_against)<x-ds.alert tone="cau" title="Taken down on report before">{{ $reports_against['items'] }} {{ $reports_against['items'] === 1 ? 'item' : 'items' }} of theirs {{ $reports_against['items'] === 1 ? 'was' : 'were' }} taken down on report in the last year.</x-ds.alert>@endif
            </section>
        </aside>
    </div>
</x-operator-layout>
