<x-operator-layout>
    <x-slot:title>Often needed with</x-slot:title>
    <x-slot:heading>Often needed with</x-slot:heading>
    <x-slot:lead>Editorial rules: "listings in this category are often needed with listings in that one". Shoppers see them labelled as our suggestion, never as something measured. Rules for a category apply to the categories inside it.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Merchandising', 'href' => route('operator.merch.index')], ['label' => 'Often needed with']]" /></x-slot:breadcrumbs>

    <div class="op-split">
        <section class="op-block" aria-labelledby="rules-h">
            <h2 id="rules-h">Rules ({{ $rules->count() }})</h2>
            @if ($rules->isEmpty())
                <x-ds.empty-state title="No rules yet" icon="tag">Add one on the right. Until then, product pages show only what orders and views really support.</x-ds.empty-state>
            @else
                <x-ds.table caption="Often needed with rules">
                    <x-slot:head><tr><th scope="col">A listing in</th><th scope="col">is often needed with</th><th scope="col">Note</th><th scope="col"><span class="ds-visually-hidden">Actions</span></th></tr></x-slot:head>
                    @foreach ($rules as $r)
                        <tr>
                            <td data-label="A listing in">{{ $names[$r->category_slug] ?? $r->category_slug }}</td>
                            <td data-label="Is often needed with">{{ $names[$r->complement_slug] ?? $r->complement_slug }}</td>
                            <td data-label="Note">{{ $r->note ?: 'None' }}</td>
                            <td data-label="Actions">
                                <form method="POST" action="{{ route('operator.search.complements.destroy', $r->id) }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="quiet" size="sm" aria-label="Remove the rule {{ $names[$r->category_slug] ?? $r->category_slug }} to {{ $names[$r->complement_slug] ?? $r->complement_slug }}">Remove</x-ds.button></form>
                            </td>
                        </tr>
                    @endforeach
                </x-ds.table>
            @endif
        </section>
        <aside class="op-side" aria-label="Add a rule">
            <section class="op-block">
                <h2>Add a rule</h2>
                <form method="POST" action="{{ route('operator.search.complements.store') }}" class="ds-stack">
                    @csrf
                    @php($opts = $categories->mapWithKeys(fn ($c) => [$c->slug => str_repeat('- ', $c->depth).$c->name])->all())
                    <x-ds.select label="A listing in" name="category_slug" :options="$opts" :selected="old('category_slug')" placeholder="Choose a category" required />
                    <x-ds.select label="is often needed with" name="complement_slug" :options="$opts" :selected="old('complement_slug')" placeholder="Choose a category" required />
                    <x-ds.input label="Note (optional)" name="note" :value="old('note')" maxlength="190" />
                    <x-ds.button type="submit">Add rule</x-ds.button>
                </form>
            </section>
        </aside>
    </div>
</x-operator-layout>
