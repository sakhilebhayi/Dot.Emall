@php
    $hints = [
        'to_ship' => 'Paid parcels with something left to pack or send. Oldest ship-by date first is the one to do first.',
        'on_the_way' => 'Shipped, or ready for the buyer to collect. Mark it delivered when it has arrived.',
        'delivered' => 'Delivered or collected. Your earnings are released a few days after delivery.',
        'cancelled' => 'Every item in the parcel was cancelled.',
        'unpaid' => 'The buyer has not paid yet. Do not pack or ship these: you can only move a parcel once it is paid.',
    ];
@endphp
<x-seller-layout>
    <x-slot:title>Orders</x-slot:title>
    <x-slot:heading>Orders</x-slot:heading>
    <x-slot:lead>Your share of each order, as a parcel. You see only your own items.</x-slot:lead>

    <div class="sd-page">
        <x-seller.errors />
        <nav class="sd-tabnav" aria-label="Parcel status">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('seller.orders.index', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}<span class="{{ $counts[$key] ? 'ds-count' : 'sd-tabnav__zero' }}">{{ $counts[$key] }}</span></a>
            @endforeach
            <a href="{{ route('seller.returns') }}">Returns</a>
        </nav>
        <p class="sd-muted">{{ $hints[$tab] }}</p>

        @if ($groups->isEmpty())
            <x-ds.empty-state title="Nothing here" icon="package-check">
                @if ($tab === 'to_ship') No paid parcels are waiting for you. When a buyer pays, the parcel appears here and you are sent a notice.
                @else No parcels in this tab. @endif
            </x-ds.empty-state>
        @else
            <ul class="sd-list">
                @foreach ($groups as $g)
                    @php
                        $order = $g->order;
                        $live = $g->items->reject->isCancelled();
                        $net = $live->sum(fn ($l) => $l->netCents());
                        $names = $live->take(2)->map(fn ($l) => $l->displayName().($l->quantity > 1 ? ' x'.$l->quantity : ''))->implode(', ');
                        $more = $live->count() - 2;
                        $by = $tab === 'to_ship' ? $shipBy($g) : null;
                        $late = $by && $by->copy()->endOfDay()->isPast();
                        $derived = \App\Enums\OrderStatus::derive($g->items->pluck('status'));
                    @endphp
                    <li class="sd-item sd-item--nobox">
                        <div class="sd-item__main">
                            <div class="sd-cluster">
                                <a class="sd-item__title" href="{{ route('seller.orders.show', $g) }}">Order {{ $order->reference() }}</a>
                                <span class="sd-quiet">placed {{ $order->created_at->format('j M Y') }}</span>
                            </div>
                            <p class="sd-muted">{{ $names }}@if ($more > 0) and {{ $more }} more @endif</p>
                            <div class="sd-item__meta">
                                <span class="sd-num"><strong>{{ $order->money($net) }}</strong> your items</span>
                                <span>{{ $g->method_name ?: 'Delivery arranged' }}</span>
                                @if ($tab === 'to_ship' && $order->shipping_name)<span>For {{ $order->shipping_name }}</span>@endif
                                @if ($by)<span @if ($late) style="color: var(--crit); font-weight: 650;" @endif>{{ $late ? 'Was due to ship by ' : 'Ship by ' }}{{ $by->format('D j M') }}</span>@endif
                                @if ($g->shipments->first()?->tracking_number)<span class="sd-code">{{ $g->shipments->first()->tracking_number }}</span>@endif
                            </div>
                        </div>
                        <div class="sd-item__side">
                            @if ($tab === 'unpaid')<x-ds.status state="awaiting_payment" />
                            @else<x-ds.status :state="$derived->value" />@endif
                            <x-ds.button variant="{{ $tab === 'to_ship' ? 'primary' : 'quiet' }}" size="sm" :href="route('seller.orders.show', $g)">{{ $tab === 'to_ship' ? 'Pack and ship' : 'Open' }}</x-ds.button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <x-ds.pagination :paginator="$groups" />
        @endif
    </div>
</x-seller-layout>
