<x-operator-layout>
    <x-slot:title>{{ $product->name }}</x-slot:title>
    <x-slot:heading>{{ $product->name }}</x-slot:heading>
    <x-slot:lead>Listing review. Everything the seller entered, as a shopper would meet it, with what we know about the store.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Listings', 'href' => route('operator.products.index')], ['label' => $product->name]]" /></x-slot:breadcrumbs>
    <x-slot:actions><x-ds.status kind="product" :state="$product->status" />@if ($product->status === 'approved')<x-ds.button variant="quiet" :href="route('products.show', $product)" icon="external">Open the shop page</x-ds.button>@endif</x-slot:actions>

    <div class="op-split">
        <div class="ds-stack">
            @if ($flags->isNotEmpty())
                <x-ds.alert tone="cau" title="The rules flagged this listing">
                    @foreach ($flags as $flag)<p><strong>{{ $flag['rule_label'] }}.</strong> {{ $flag['detail'] }}</p>@endforeach
                    <p class="op-muted">A flag is a question, not a verdict. You decide.</p>
                </x-ds.alert>
            @endif
            @if ($reports > 0)<x-ds.alert tone="crit" title="{{ $reports }} open {{ \Illuminate\Support\Str::plural('report', $reports) }}"><a href="{{ route('operator.reports.show', ['type' => 'product', 'id' => $product->id]) }}">Read the reports</a></x-ds.alert>@endif
            @if ($product->status === 'rejected' && $product->rejected_reason)
                <p class="op-quote">{{ $product->rejected_reason }}<small>Shown to the seller as the reason</small></p>
            @endif

            @if ($product->images->isNotEmpty())
                <div class="op-thumbs" role="list" aria-label="Photographs">
                    @foreach ($product->images as $image)<img role="listitem" src="{{ $image->url() }}" alt="Photograph {{ $loop->iteration }} of {{ $loop->count }}" width="96" height="96" loading="lazy">@endforeach
                </div>
            @else
                <x-ds.alert tone="cau">The seller added no photograph.</x-ds.alert>
            @endif

            <section class="op-block" aria-labelledby="desc-h">
                <h2 id="desc-h">Description</h2>
                @if (filled($product->description))<p class="op-prose">{{ $product->description }}</p>@else<p class="op-muted">The seller wrote no description.</p>@endif
            </section>

            <section class="op-block" aria-labelledby="facts-h">
                <h2 id="facts-h">Details</h2>
                <x-ds.dl>
                    <dt>Price</dt><dd class="op-num">{{ \App\Support\Shop::amount($product->price) }}</dd>
                    <dt>Category</dt><dd>{{ $product->category?->name ?? 'None chosen' }}</dd>
                    <dt>Brand</dt><dd>{{ $product->brand?->name ?? 'None' }}</dd>
                    <dt>Stock</dt><dd class="op-num">{{ number_format($product->stock) }}</dd>
                    <dt>SKU</dt><dd class="op-code">{{ $product->sku ?: 'None' }}</dd>
                    @foreach ($product->attributeValues as $attribute)<dt>{{ $attribute->label ?? $attribute->key }}</dt><dd>{{ $attribute->value }}{{ $attribute->unit ? ' '.$attribute->unit : '' }}</dd>@endforeach
                    @if ($product->variants->where('is_default', false)->isNotEmpty())
                        <dt>Choices</dt><dd>{{ $product->variants->where('is_active', true)->where('is_default', false)->count() }} variants: {{ $product->options->map(fn ($o) => $o->name)->implode(', ') }}</dd>
                    @endif
                </x-ds.dl>
            </section>

            @if ($product->status === 'pending_approval' || $product->status === 'approved')
                <section class="op-block" aria-labelledby="decide-h">
                    <h2 id="decide-h">{{ $product->status === 'pending_approval' ? 'Your decision' : 'Take it down' }}</h2>
                    <div class="op-decisions">
                        @if ($product->status === 'pending_approval')
                            <form method="POST" action="{{ route('operator.products.approve', $product) }}">@csrf<x-ds.button type="submit">Approve listing</x-ds.button></form>
                        @endif
                        <x-op.decide :bag="'reject-'.$product->id" :action="route('operator.products.reject', $product)" :summary="$product->status === 'approved' ? 'Take the listing down' : 'Reject listing'" :submit="$product->status === 'approved' ? 'Take down and tell the seller' : 'Reject and tell the seller'" tone="danger" label="Reason the seller will read" hint="Say what to fix, in a sentence they can act on. It is shown on their listing and sent as a notice." :templates="array_flip($templates)" />
                    </div>
                    @if ($errors->getBag('approve-'.$product->id)->any())<x-ds.alert tone="crit">{{ $errors->getBag('approve-'.$product->id)->first() }}</x-ds.alert>@endif
                </section>
            @endif
        </div>

        <aside class="op-side" aria-label="The seller">
            <section class="op-block">
                <h2><a href="{{ route('operator.stores.show', $product->store) }}">{{ $product->store->name }}</a></h2>
                <p class="op-muted">Owner {{ $product->store->owner?->name }} · opened <x-op.age :at="$product->store->created_at" /> ago · {{ $product->store->status->label() }}</p>
                @include('operator.partials.ladder', ['trust' => $trust])
            </section>
            <section class="op-block">
                <h2>History of this listing</h2>
                @forelse ($history as $entry)
                    <p class="op-muted"><strong>{{ $entry->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($entry->action)) }}, <x-op.age :at="$entry->created_at" /> ago.@if ($entry->reason)<br>{{ $entry->reason }}@endif</p>
                @empty
                    <p class="op-muted">No decision recorded yet. Submitted <x-op.age :at="$product->created_at" /> ago.</p>
                @endforelse
            </section>
        </aside>
    </div>
</x-operator-layout>
