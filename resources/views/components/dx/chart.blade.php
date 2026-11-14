{{--
  dx.chart (R-51): the seller's one chart, sales per day (per week from 90 days) for one store in the shop currency, bars from zero. One series, so no legend: the heading names it and the
  peak is labelled. A day with no sale draws nothing (an honest gap) and says so on hover; days with sales are keyboard stops. The same figures are in the table underneath.
  The axis prints zero and one round ceiling only. Props: chart (App\Services\Dashboard\SalesChart::build), period (e.g. "last 30 days"). The tooltip and the hover come from the dashboard script (the chart reads fine without it).
--}}
@props(['chart', 'period'])
@php use App\Services\Dashboard\SalesChart as C; @endphp
@if ($chart['hasSales'])
    <div class="dx-chart" data-chart>
        <svg viewBox="0 0 {{ C::WIDTH }} {{ C::HEIGHT }}" role="group" aria-label="Sales per day, {{ $period }}. {{ $chart['summary'] }}">
            <line class="dx-chart__grid" x1="{{ C::LEFT }}" x2="{{ C::WIDTH }}" y1="{{ C::TOP }}" y2="{{ C::TOP }}"/>
            <line class="dx-chart__base" x1="{{ C::LEFT }}" x2="{{ C::WIDTH }}" y1="{{ C::BASE }}" y2="{{ C::BASE }}"/>
            <text class="dx-chart__tick" x="{{ C::LEFT - 8 }}" y="{{ C::TOP + 4 }}" text-anchor="end">{{ $chart['maxLabel'] }}</text>
            <text class="dx-chart__tick" x="{{ C::LEFT - 8 }}" y="{{ C::BASE + 4 }}" text-anchor="end">{{ \App\Support\Shop::money(0) }}</text>
            @php $k = 0; @endphp
            @foreach ($chart['bars'] as $bar)
                <rect class="dx-chart__hit" x="{{ $bar['x'] }}" y="0" width="{{ $bar['w'] }}" height="{{ C::BASE }}" data-t="{{ $bar['tip'] }}" @if ($bar['cents'] > 0) tabindex="0" role="img" aria-label="{{ $bar['tip'] }}" @endif/>
                @if ($bar['path'])<path class="dx-chart__bar" style="--k: {{ $k++ }}" d="{{ $bar['path'] }}"/>@endif
            @endforeach
            @foreach ($chart['bars'] as $bar)
                @if ($bar['peak'])<text class="dx-chart__peak" x="{{ min(max($bar['labelX'], C::LEFT + 24), C::WIDTH - 24) }}" y="{{ $bar['top'] - 6 }}" text-anchor="middle">{{ $chart['peakLabel'] }}</text>@endif
            @endforeach
            <text class="dx-chart__tick" x="{{ C::LEFT }}" y="{{ C::HEIGHT - 4 }}">{{ $chart['first'] }}</text>
            <text class="dx-chart__tick" x="{{ C::WIDTH }}" y="{{ C::HEIGHT - 4 }}" text-anchor="end">{{ $chart['last'] }}</text>
        </svg>
        <div class="dx-chart__tip" aria-hidden="true"></div>
    </div>
    <details class="dx-tv">
        <summary>View as a table</summary>
        <table class="dx-tbl dx-tbl--plain">
            <thead><tr><th scope="col">Day</th><th scope="col" class="is-num">Sales</th></tr></thead>
            <tbody>@foreach ($chart['rows'] as $row)<tr><td>{{ $row['label'] }}</td><td class="is-num">{{ $row['amount'] }}</td></tr>@endforeach</tbody>
        </table>
        <p class="dx-note">Days with no sale are zero and are not listed.</p>
    </details>
@else
    <div class="dx-chart dx-chart--empty dx-hatch" role="img" aria-label="No sales to chart yet"></div>
@endif
