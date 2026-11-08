@php
    use App\Services\Operator\OperatorMetrics;

    $byKey = collect($report['metrics'])->keyBy('key');
    $groups = [
        'Money and orders' => ['gmv', 'aov', 'repeat_purchase_rate', 'cancellation_rate', 'refund_rate', 'return_rate', 'dispute_rate', 'payment_failure_rate'],
        'How people shop' => ['discovery_success', 'search_to_product', 'add_to_cart_rate', 'checkout_completion', 'cart_abandonment'],
        'The marketplace' => ['active_sellers', 'active_products', 'seller_activity', 'seller_fulfilment'],
    ];
    $series = $report['series'];
    $chartOrders = collect($series)->sum('orders');
    $periodLabel = $report['from']->format('d M').' to '.$report['to']->copy()->subDay()->format('d M Y');

    // One small bar chart as inline SVG: zero baseline, thin bars, a gridline at the top value, first and last day labelled. Only drawn when there is something to draw.
    $bars = function (array $rows, string $field, callable $fmt, string $name) {
        $max = max(1, collect($rows)->max($field));
        $w = 640; $h = 160; $left = 84; $top = 14; $bottom = 24; $n = max(1, count($rows));
        $step = ($w - $left) / $n; $bw = max(2, min(14, $step * 0.62));
        return compact('max', 'w', 'h', 'left', 'top', 'bottom', 'n', 'step', 'bw', 'fmt', 'name', 'rows', 'field');
    };
    $charts = [
        'orders' => $bars($series, 'orders', fn ($v) => number_format($v), 'Orders per day'),
        'gmv' => $bars($series, 'gmv_cents', fn ($v) => \App\Support\Shop::money((int) $v), 'Order value per day'),
    ];
@endphp
<x-operator-layout>
    <x-slot:title>Metrics</x-slot:title>
    <x-slot:heading>Metrics</x-slot:heading>
    <x-slot:lead>Counted from real orders, searches and returns. Each figure prints how it is defined and how many events stand behind it. Where there is nothing to measure it says "No data yet", never zero.</x-slot:lead>
    <x-slot:actions><x-ds.button variant="quiet" :href="route('operator.metrics.export', ['period' => $report['period']])" icon="upload">Download CSV</x-ds.button></x-slot:actions>

    <x-op.chips label="Period" :items="collect($periods)->map(fn ($label, $key) => ['label' => $label, 'href' => route('operator.metrics', ['period' => $key]), 'current' => $report['period'] === $key])->values()->all()" />
    <p class="op-muted">{{ $periodLabel }}. Figures refresh at most once a minute.</p>

    <section class="op-block op-charts" aria-label="Charts">
            @if ($chartOrders === 0)
                <section class="op-block"><h2>Orders per day</h2><p class="op-muted">No data yet. No order was counted between {{ $periodLabel }}, so there is nothing to draw.</p></section>
            @else
                @foreach ($charts as $c)
                    @php $pts = collect($c['rows']); @endphp
                    <figure class="op-chart-figure op-block">
                        <figcaption>{{ $c['name'] }}</figcaption>
                        <svg class="op-chart" viewBox="0 0 {{ $c['w'] }} {{ $c['h'] }}" role="img" aria-label="{{ $c['name'] }}, {{ $periodLabel }}. Highest day: {{ ($c['fmt'])($c['max']) }}. The table below has every day.">
                            <line class="op-chart__grid" x1="{{ $c['left'] }}" x2="{{ $c['w'] }}" y1="{{ $c['top'] }}" y2="{{ $c['top'] }}" />
                            <text class="op-chart__text" x="{{ $c['left'] - 6 }}" y="{{ $c['top'] + 4 }}" text-anchor="end">{{ ($c['fmt'])($c['max']) }}</text>
                            <text class="op-chart__text" x="{{ $c['left'] - 6 }}" y="{{ $c['h'] - $c['bottom'] + 4 }}" text-anchor="end">0</text>
                            @foreach ($pts as $i => $row)
                                @php
                                    $plot = $c['h'] - $c['top'] - $c['bottom'];
                                    $bh = $row[$c['field']] > 0 ? max(2, $plot * $row[$c['field']] / $c['max']) : 0;
                                    $x = $c['left'] + $i * $c['step'] + ($c['step'] - $c['bw']) / 2;
                                @endphp
                                @if ($bh > 0)<rect class="op-chart__bar" x="{{ round($x, 1) }}" y="{{ round($c['h'] - $c['bottom'] - $bh, 1) }}" width="{{ round($c['bw'], 1) }}" height="{{ round($bh, 1) }}" rx="2"><title>{{ \Illuminate\Support\Carbon::parse($row['day'])->format('d M') }}: {{ ($c['fmt'])($row[$c['field']]) }}</title></rect>@endif
                            @endforeach
                            <line class="op-chart__base" x1="{{ $c['left'] }}" x2="{{ $c['w'] }}" y1="{{ $c['h'] - $c['bottom'] }}" y2="{{ $c['h'] - $c['bottom'] }}" />
                            <text class="op-chart__text" x="{{ $c['left'] }}" y="{{ $c['h'] - 6 }}">{{ \Illuminate\Support\Carbon::parse($pts->first()['day'])->format('d M') }}</text>
                            <text class="op-chart__text" x="{{ $c['w'] }}" y="{{ $c['h'] - 6 }}" text-anchor="end">{{ \Illuminate\Support\Carbon::parse($pts->last()['day'])->format('d M') }}</text>
                        </svg>
                        <details>
                            <summary>Show as a table</summary>
                            <x-ds.table :caption="$c['name']" :stack="false">
                                <x-slot:head><tr><th scope="col">Day</th><th scope="col" class="is-num">{{ $c['field'] === 'orders' ? 'Orders' : 'Value' }}</th></tr></x-slot:head>
                                @foreach ($pts->filter(fn ($r) => $r[$c['field']] > 0) as $row)<tr><td>{{ \Illuminate\Support\Carbon::parse($row['day'])->format('d M Y') }}</td><td class="is-num">{{ ($c['fmt'])($row[$c['field']]) }}</td></tr>@endforeach
                            </x-ds.table>
                            <p class="op-muted">Days with no counted order are left out of the table; the chart draws them at zero.</p>
                        </details>
                    </figure>
                @endforeach
            @endif
    </section>

    <div class="op-split op-split--wide-aside">
        <div class="ds-stack">
            @foreach ($groups as $title => $keys)
                <section class="op-block" aria-labelledby="g-{{ \Illuminate\Support\Str::slug($title) }}">
                    <h2 id="g-{{ \Illuminate\Support\Str::slug($title) }}">{{ $title }}</h2>
                    <ul class="op-metrics">
                        @foreach ($keys as $key)
                            @php $m = $byKey[$key]; @endphp
                            <li class="op-metric">
                                <span class="op-metric__label">{{ $m->label }}</span>
                                <div class="op-metric__main">
                                    <p class="op-metric__figure"><span class="op-metric__value" {{ $m->hasData() ? '' : 'data-empty' }}>{{ OperatorMetrics::display($m) }}</span> <span class="op-metric__basis">@if ($m->hasData() && $m->numerator !== null){{ number_format($m->numerator) }} of {{ number_format($m->denominator) }}@elseif (! $m->hasData() && $m->note){{ $m->note }}@endif</span></p>
                                    <p class="op-metric__def">{{ $m->definition }}@if ($m->hasData() && $m->note) {{ $m->note }}@endif @if ($m->key === 'search_to_product' && $m->hasData() && $m->value > 1) More than 100% means people opened several results per search. @endif</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        <aside class="op-side" aria-label="How to read this">
            <section class="op-block">
                <h2>Where "counted" comes from</h2>
                <p class="op-muted">A counted order is paid (or delivered where payment was never required) and holds at least one line that is not cancelled. A session is one person on one day: events carry a keyed hash, never a user id. Dot.Analytics receives only its own per-company snapshot (orders in 7 days, revenue in 7 days, low stock, and with a switch on, views, adds to cart and wishlist adds). This page publishes nothing.</p>
            </section>
        </aside>
    </div>
</x-operator-layout>
