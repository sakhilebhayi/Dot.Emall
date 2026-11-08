<x-operator-layout>
    <x-slot:title>Order {{ $order->reference() }}</x-slot:title>
    <x-slot:heading>Order {{ $order->reference() }}</x-slot:heading>
    <x-slot:lead>Read-only view for support. Opening this page was written to the access log.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'People', 'href' => route('operator.people.index')], ['label' => 'Order '.$order->reference()]]" /></x-slot:breadcrumbs>

    <div class="op-split">
        <div class="ds-stack">
            <section class="op-block" aria-labelledby="lines-h">
                <h2 id="lines-h">Lines</h2>
                <x-ds.table caption="Order lines">
                    <x-slot:head><tr><th scope="col">Item</th><th scope="col">Store</th><th scope="col">Status</th><th scope="col" class="is-num">Qty</th><th scope="col" class="is-num">Total</th></tr></x-slot:head>
                    @foreach ($order->items as $line)
                        <tr>
                            <td data-label="Item">{{ $line->displayName() }}</td>
                            <td data-label="Store">{{ $line->displayStoreName() }}</td>
                            <td data-label="Status">{{ ucfirst(str_replace('_', ' ', $line->status->value ?? (string) $line->status)) }}</td>
                            <td data-label="Qty" class="is-num">{{ $line->quantity }}</td>
                            <td data-label="Total" class="is-num">{{ $order->amount($line->total) }}</td>
                        </tr>
                    @endforeach
                </x-ds.table>
            </section>
            <section class="op-block" aria-labelledby="pay-h">
                <h2 id="pay-h">Payments and refunds</h2>
                @forelse ($payments as $p)
                    <p><span class="op-code">{{ $p->reference }}</span> <span class="op-muted">{{ $p->status->value }}, {{ \App\Support\Shop::money($p->amount_cents, $p->currency) }}, {{ $p->created_at->format('d M Y H:i') }}@if ($p->review_note); reviewed: {{ $p->review_note }}@endif</span></p>
                    @foreach ($p->events as $e)<p class="op-muted">Event {{ $e->event }}: {{ str_replace('_', ' ', $e->outcome) }}{{ $e->reason ? ' ('.str_replace('_', ' ', $e->reason).')' : '' }}</p>@endforeach
                @empty<p class="op-muted">No payment attempt.</p>@endforelse
                @foreach ($refunds as $r)<p>Refund #{{ $r->id }} <span class="op-muted">{{ \App\Support\Shop::money($r->amount_cents, $r->currency) }}, {{ $r->status->value }}, {{ $r->kind }}</span></p>@endforeach
            </section>
            <section class="op-block" aria-labelledby="ret-h">
                <h2 id="ret-h">Returns</h2>
                @forelse ($returns as $r)<p><a class="op-row-link" href="{{ route('operator.returns.show', $r) }}">Return #{{ $r->id }}</a> <span class="op-muted">{{ $r->status->label() }}, {{ $r->created_at->format('d M Y') }}</span></p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
            <section class="op-block" aria-labelledby="hist-h">
                <h2 id="hist-h">Status history</h2>
                @forelse ($order->statusHistory->sortBy('id') as $h)<p class="op-muted">{{ $h->created_at?->format('d M Y H:i') }}: {{ $h->from_status ? str_replace('_', ' ', $h->from_status).' to ' : '' }}{{ str_replace('_', ' ', $h->to_status) }} by {{ $h->actor_role }}{{ $h->note ? ' ('.$h->note.')' : '' }}</p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
        </div>
        <aside class="op-side" aria-label="The order">
            <section class="op-block">
                <h2>The order</h2>
                <x-ds.dl>
                    <dt>Placed</dt><dd>{{ $order->created_at->format('d M Y H:i') }}</dd>
                    <dt>Payment</dt><dd>{{ ucfirst(str_replace('_', ' ', $order->payment_status->value)) }}</dd>
                    <dt>Total</dt><dd class="op-num">{{ $order->money($order->totalCents()) }}</dd>
                    <dt>Buyer</dt><dd>@if ($order->user)<a href="{{ route('operator.people.user', $order->user) }}">{{ $order->user->name }}</a>, {{ $order->user->email }}@else Guest, {{ $order->guest_email }}@endif</dd>
                    <dt>Delivery to</dt><dd>{{ $order->shipping_name }}, {{ $order->shipping_address }}{{ $order->shipping_phone ? ', '.$order->shipping_phone : '' }}</dd>
                </x-ds.dl>
            </section>
            <section class="op-block">
                <h2>Parcels</h2>
                @forelse ($order->groups as $g)<p><strong>{{ $g->store_name }}</strong><br><span class="op-muted">{{ $g->method_name ?? 'Delivery arranged' }}, {{ ucfirst($g->status) }}{{ $g->shipped_at ? ', shipped '.$g->shipped_at->format('d M') : '' }}</span></p>@empty<p class="op-muted">None.</p>@endforelse
            </section>
            @if ($audit->isNotEmpty())
                <section class="op-block"><h2>Operator decisions</h2>@foreach ($audit as $e)<p class="op-muted"><strong>{{ $e->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($e->action)) }}. {{ $e->reason }}</p>@endforeach</section>
            @endif
        </aside>
    </div>
</x-operator-layout>
