{{--
  ds.table: a real <table>. Props: caption=null (printed above, and the table's name), stack=true (under 40rem every row becomes a labelled block: give each <td> a data-label), numeric columns take class="is-num".
  Slots: head (the <tr> of <th scope="col">), default (the <tr> rows). Wrap-scrolls sideways when it cannot stack.
--}}
@props(['caption' => null, 'stack' => true])
<div class="ds-table-wrap" tabindex="0" role="region" @if ($caption) aria-label="{{ $caption }}" @endif>
    <table {{ $attributes->class(['ds-table', 'ds-table--stack' => $stack]) }}>
        @if ($caption)<caption>{{ $caption }}</caption>@endif
        @isset($head)<thead>{{ $head }}</thead>@endisset
        <tbody>{{ $slot }}</tbody>
    </table>
</div>
