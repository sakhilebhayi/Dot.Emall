{{-- The seller's trust ladder as recorded data. Variable: $trust (App\Services\Stores\StoreTrust::for). Claimed numbers are labelled claimed; nothing here is verified by us. --}}
<ul class="op-ladder" aria-label="What is known about this seller">
    @foreach ($trust['ladder'] as $rung)
        @php $icon = ['yes' => 'check-circle', 'claimed' => 'info', 'no' => 'minus-circle'][$rung['state']] ?? 'info'; @endphp
        <li data-state="{{ $rung['state'] }}">
            <x-ds.icon :name="$icon" :size="18" />
            <span>
                {{ $rung['label'] }}
                @php
                    $how = match ($rung['state']) {
                        'yes' => 'Recorded by '.$rung['source'],
                        'claimed' => 'Claimed by the seller, not checked',
                        default => 'Not provided',
                    };
                    $detail = $rung['state'] === 'claimed' ? \Illuminate\Support\Str::before((string) ($rung['detail'] ?? ''), ' (') : ($rung['detail'] ?? '');
                @endphp
                <small>{{ $how }}{{ filled($detail) ? ' · '.$detail : '' }}</small>
            </span>
        </li>
    @endforeach
    @foreach (['fulfilment_rate' => 'Shipped on time', 'median_hours_to_ship' => 'Median time to ship', 'return_rate' => 'Items with a return requested'] as $key => $label)
        @php($m = $trust['metrics'][$key])
        <li data-state="{{ $m['value'] === null ? 'no' : 'yes' }}">
            <x-ds.icon :name="$m['value'] === null ? 'minus-circle' : 'chart'" :size="18" />
            <span>{{ $label }}: <strong class="op-num">{{ $m['value'] === null ? 'not enough orders yet' : $m['value'].($m['unit'] === 'percent' ? '%' : ' h') }}</strong>
                <small>Measured on Emall, {{ $m['sample'] }} {{ $m['sample'] === 1 ? 'sample' : 'samples' }}</small></span>
        </li>
    @endforeach
    <li data-state="{{ $trust['metrics']['review_count']['value'] > 0 ? 'yes' : 'no' }}">
        <x-ds.icon name="star" :size="18" />
        <span>Reviews: <strong class="op-num">{{ $trust['metrics']['review_count']['value'] }}</strong><small>{{ $trust['metrics']['review_average']['note'] }}</small></span>
    </li>
</ul>
