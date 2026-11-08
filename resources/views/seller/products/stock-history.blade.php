@php
    $reasons = ['sale' => 'Sold', 'seller_edit' => 'You changed it', 'initial' => 'Opening stock', 'adjustment' => 'Changed on the product', 'cancellation' => 'Order cancelled', 'expiry' => 'Unpaid order expired', 'return' => 'Return restocked'];
    $variants = $product->variants()->get();
@endphp
<x-seller-layout>
    <x-slot:title>Stock history for {{ $product->name }}</x-slot:title>
    <x-slot:heading>Stock history</x-slot:heading>
    <x-slot:lead>{{ $product->name }}: every change to the number in stock, newest first.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Products', 'href' => route('seller.products.index')], ['label' => $product->name, 'href' => route('seller.products.edit', $product)], ['label' => 'Stock history']]" /></x-slot:breadcrumbs>

    <div class="sd-page">
        <x-seller.errors />
        @if ($variants->count() > 0)
            <section aria-labelledby="sh-adjust" class="sd-stack">
                <h2 id="sh-adjust" class="sd-h2">Set stock</h2>
                <p class="sd-muted sd-measure">Type how many you have now. If buyers buy some while you type, your count is applied to the new number, never the old one.</p>
                <form method="POST" action="{{ route('seller.products.stock', $product) }}" class="sd-stack" novalidate>
                    @csrf
                    <div class="sd-fields sd-fields--three">
                        @foreach ($variants->where('is_active', true) as $v)
                            <div>
                                <x-ds.input :label="$v->label() ?: $product->name" :name="'stock['.$v->id.'][value]'" :id="'stock-'.$v->id" type="number" min="0" inputmode="numeric" :value="old('stock.'.$v->id.'.value', $v->stock)" :error="$errors->first('stock.'.$v->id.'.value')" :hint="$v->stock.' now'" />
                                <input type="hidden" name="stock[{{ $v->id }}][was]" value="{{ $v->stock }}">
                            </div>
                        @endforeach
                    </div>
                    <div><x-ds.button type="submit" variant="primary">Save stock</x-ds.button></div>
                </form>
            </section>
        @endif

        <section aria-labelledby="sh-list">
            <h2 id="sh-list" class="sd-h2" style="margin-block-end: var(--s-3);">Changes</h2>
            @if ($movements->isEmpty())
                <p class="sd-muted">No stock changes recorded yet.</p>
            @else
                <x-ds.table caption="Stock changes" :stack="true">
                    <x-slot:head><tr><th scope="col">When</th><th scope="col">What</th><th scope="col">Option</th><th scope="col" class="is-num">Change</th><th scope="col" class="is-num">Stock after</th><th scope="col">By</th></tr></x-slot:head>
                    @foreach ($movements as $m)
                        <tr>
                            <td data-label="When">{{ $m->created_at->format('j M Y H:i') }}</td>
                            <td data-label="What">{{ $reasons[$m->reason] ?? ucfirst(str_replace('_', ' ', $m->reason)) }}@if ($m->order_id) <span class="sd-quiet">order #{{ str_pad((string) $m->order_id, 5, '0', STR_PAD_LEFT) }}</span>@endif @if ($m->note)<br><span class="sd-quiet">{{ $m->note }}</span>@endif</td>
                            <td data-label="Option">{{ $m->variant?->label() ?: 'Whole product' }}</td>
                            <td data-label="Change" class="is-num">{{ $m->delta > 0 ? '+' : '' }}{{ $m->delta }}</td>
                            <td data-label="Stock after" class="is-num">{{ $m->stock_after }}</td>
                            <td data-label="By">{{ $m->actor?->name ?? ($m->order_id ? 'A buyer' : 'The system') }}</td>
                        </tr>
                    @endforeach
                </x-ds.table>
                <x-ds.pagination :paginator="$movements" />
            @endif
        </section>
    </div>
</x-seller-layout>
