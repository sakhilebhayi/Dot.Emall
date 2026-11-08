@php
    $rule = (array) old('rule', $collection->rule ?? []);
    $type = old('type', $collection->type ?? 'manual');
    $bag = $errors->getBag('collection');
    $fmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d\TH:i') : '';
@endphp
<x-operator-layout>
    <x-slot:title>{{ $collection->exists ? 'Edit '.$collection->title : 'New collection' }}</x-slot:title>
    <x-slot:heading>{{ $collection->exists ? $collection->title : 'New collection' }}</x-slot:heading>
    <x-slot:lead>Choose how it is filled, set the dates, and publish when it reads right. The preview runs the same search as the shop.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Merchandising', 'href' => route('operator.merch.index')], ['label' => 'Collections', 'href' => route('operator.collections.index')], ['label' => $collection->exists ? 'Edit' : 'New']]" /></x-slot:breadcrumbs>

    @if ($bag->any())<x-ds.alert tone="crit" title="It was not saved">@foreach ($bag->all() as $m)<p>{{ $m }}</p>@endforeach</x-ds.alert>@endif

    <form method="POST" action="{{ $collection->exists ? route('operator.collections.update', $collection) : route('operator.collections.store') }}" class="ds-stack" data-collection-preview="{{ route('operator.collections.preview') }}">
        @csrf
        @if ($collection->exists)@method('PATCH')@endif

        <div class="op-form-grid">
            <x-ds.input class="op-span" label="Title" name="title" :value="old('title', $collection->title)" required maxlength="120" />
            <x-ds.textarea class="op-span" label="Description" name="description" :value="old('description', $collection->description)" rows="3" hint="Shown above the collection on the shop. Say what ties it together." />
            <x-ds.radio class="op-span" legend="How is it filled?" name="type" :options="['manual' => 'Hand-picked list', 'dynamic' => 'A rule, re-run every time it is viewed']" :selected="$type" />
            <x-ds.input label="Starts" name="starts_at" type="datetime-local" :value="old('starts_at', $fmt($collection->starts_at))" hint="Leave empty to start as soon as it is published." />
            <x-ds.input label="Ends" name="ends_at" type="datetime-local" :value="old('ends_at', $fmt($collection->ends_at))" hint="Leave empty to run until you stop it." />
            <x-ds.select label="Publishing" name="visibility" :options="['draft' => 'Draft: only operators can see it', 'public' => 'Published: shoppers see it between its dates']" :selected="old('visibility', $collection->visibility)" />
        </div>

        <section class="op-block" aria-labelledby="manual-h" @if ($type !== 'manual') hidden @endif data-when-type="manual">
            <h2 id="manual-h">Hand-picked listings</h2>
            <div data-collection-find="{{ route('operator.collections.find') }}" data-target="product-ids" class="ds-stack">
                <x-ds.field label="Find a listing to add" id="find-product"><input id="find-product" type="search" class="ds-input" autocomplete="off" placeholder="Start typing a listing name"></x-ds.field>
                <div data-find-results aria-live="polite"></div>
            </div>
            <x-ds.textarea label="Listing numbers, in the order shoppers see them" name="product_ids" id="product-ids" :value="old('product_ids', implode("\n", $productIds))" rows="4" hint="One per line. Adding from the search above fills this in. A listing that is not buyable right now is skipped when shoppers view the collection." />
        </section>

        <section class="op-block" aria-labelledby="rule-h" @if ($type !== 'dynamic') hidden @endif data-when-type="dynamic">
            <h2 id="rule-h">The rule</h2>
            <div class="op-form-grid">
                <x-ds.select label="Category" name="rule[category]" id="rule-category" :options="$categories->mapWithKeys(fn ($c) => [$c->id => str_repeat('- ', $c->depth).$c->name])->all()" :selected="$rule['category'] ?? ''" placeholder="Any category" hint="Includes the categories inside it." />
                <x-ds.input label="Brand" name="rule[brand]" id="rule-brand" :value="$rule['brand'] ?? ''" />
                <x-ds.input label="Lowest price" name="rule[price_min]" id="rule-price-min" type="number" min="0" step="0.01" :value="$rule['price_min'] ?? ''" />
                <x-ds.input label="Highest price" name="rule[price_max]" id="rule-price-max" type="number" min="0" step="0.01" :value="$rule['price_max'] ?? ''" />
                <x-ds.select label="Rating at least" name="rule[rating]" id="rule-rating" :options="['4' => '4 stars and up', '3' => '3 stars and up']" :selected="$rule['rating'] ?? ''" placeholder="Any rating" />
                <x-ds.input label="Words" name="rule[q]" id="rule-q" :value="$rule['q'] ?? ''" hint="Optional: the listing must also match these words." />
                <x-ds.select label="Order" name="rule[sort]" id="rule-sort" :options="['newest' => 'Newest first', 'price_asc' => 'Cheapest first', 'price_desc' => 'Dearest first', 'rating' => 'Best rated', 'best_selling' => 'Best selling']" :selected="$rule['sort'] ?? 'newest'" />
                <x-ds.checkbox name="rule[in_stock]" id="rule-in-stock" value="1" label="Only listings in stock" :checked="! empty($rule['in_stock'])" />
            </div>
            <div class="op-preview" data-preview-out role="status" aria-live="polite"><p class="op-muted">The preview shows what the rule matches right now.</p></div>
        </section>

        <div class="op-inline">
            <x-ds.button type="submit">{{ $collection->exists ? 'Save collection' : 'Create collection' }}</x-ds.button>
            <x-ds.button variant="quiet" :href="route('operator.collections.index')">Cancel</x-ds.button>
        </div>
    </form>

    @if ($collection->exists)
        <section class="op-block" aria-labelledby="del-h">
            <h2 id="del-h">Delete</h2>
            <p class="op-muted">Removes the collection only, never the listings in it. It cannot be undone, and the audit trail keeps a record.</p>
            <x-ds.button variant="danger" data-dialog-open="del-collection">Delete collection</x-ds.button>
        </section>
        <x-ds.confirm-dialog id="del-collection" title="Delete this collection?" :action="route('operator.collections.destroy', $collection)" method="DELETE" confirm-label="Delete collection">
            <p>"{{ $collection->title }}" will be removed from the shop. The listings in it stay where they are.</p>
        </x-ds.confirm-dialog>
    @endif
</x-operator-layout>
