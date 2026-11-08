@php
    $hints = [
        'to_answer' => 'A buyer asked to return something. Approve or decline, with a reason if you decline. If you do not answer in '.config('emall.returns.seller_response_days', 10).' days it goes to the Emall team.',
        'approved' => 'You approved these. When the goods arrive back, mark them received and the buyer is refunded.',
        'declined' => 'You declined these. The buyer can ask the Emall team to look again.',
        'escalated' => 'With the Emall team, who will decide. You will be told the outcome.',
        'done' => 'Finished. Refunds come off your ledger when they are recorded.',
    ];
@endphp
<x-seller-layout>
    <x-slot:title>Returns</x-slot:title>
    <x-slot:heading>Returns</x-slot:heading>
    <x-slot:lead>Buyers can ask to return an item for {{ config('emall.returns.window_days', 7) }} days after it arrives.</x-slot:lead>

    <div class="sd-page">
        <x-seller.errors />
        <nav class="sd-tabnav" aria-label="Return status">
            @foreach ($tabs as $key => [$label])
                <a href="{{ route('seller.returns', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $label }}<span class="{{ $counts[$key] ? 'ds-count' : 'sd-tabnav__zero' }}">{{ $counts[$key] }}</span></a>
            @endforeach
        </nav>
        <p class="sd-muted sd-measure">{{ $hints[$tab] }}</p>

        @if ($returns->isEmpty())
            <x-ds.empty-state title="No returns here" icon="return">Nothing to do in this tab.</x-ds.empty-state>
        @else
            <ul class="sd-list">
                @foreach ($returns as $r)
                    @php $days = $r->status === \App\Enums\ReturnStatus::Requested && $r->respond_by ? (int) now()->startOfDay()->diffInDays($r->respond_by->copy()->startOfDay(), false) : null; @endphp
                    <li class="sd-item sd-item--nobox">
                        <div class="sd-item__main">
                            <a class="sd-item__title" href="{{ route('seller.returns.show', $r) }}">{{ $r->item?->displayName() ?? 'Item' }}</a>
                            <div class="sd-item__meta">
                                <span>{{ $r->reason_category->label() }}</span>
                                <span>Asked {{ $r->created_at->format('j M Y') }}</span>
                                <span class="sd-num">{{ $r->order->money($r->refund_cents) }} to refund</span>
                                @if ($days !== null)<span @if ($days <= 2) style="color: var(--crit); font-weight: 650;" @endif>{{ $days < 0 ? 'Past the deadline' : ($days === 0 ? 'Answer today' : $days.' '.($days === 1 ? 'day' : 'days').' left to answer') }}</span>@endif
                            </div>
                        </div>
                        <div class="sd-item__side">
                            <x-ds.badge :tone="in_array($r->status->value, ['requested', 'escalated'], true) ? 'cau' : ($r->status->value === 'declined' ? 'crit' : 'info')">{{ $r->status->label() }}</x-ds.badge>
                            <x-ds.button :variant="$r->status === \App\Enums\ReturnStatus::Requested ? 'primary' : 'quiet'" size="sm" :href="route('seller.returns.show', $r)">{{ $r->status === \App\Enums\ReturnStatus::Requested ? 'Answer' : 'Open' }}</x-ds.button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <x-ds.pagination :paginator="$returns" />
        @endif
    </div>
</x-seller-layout>
