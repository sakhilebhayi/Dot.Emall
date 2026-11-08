<x-operator-layout>
    <x-slot:title>Categories</x-slot:title>
    <x-slot:heading>Categories</x-slot:heading>
    <x-slot:lead>The category tree shoppers browse. Hiding a category removes it from the shop's menus without deleting it or its listings. The attributes you define here are the fields sellers fill in, and the filters shoppers get.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Merchandising', 'href' => route('operator.merch.index')], ['label' => 'Categories']]" /></x-slot:breadcrumbs>

    <div class="op-split op-split--wide-aside">
        <div class="ds-stack">
            @if ($selected)
                @php($bag = $errors->getBag('category'))
                <section class="op-block" aria-labelledby="edit-h">
                    <h2 id="edit-h">{{ $selected->name }}</h2>
                    @if ($bag->any())<x-ds.alert tone="crit">{{ $bag->first() }}</x-ds.alert>@endif
                    <form method="POST" action="{{ route('operator.catalogue.categories.update', $selected) }}" class="ds-stack">
                        @csrf @method('PATCH')
                        <div class="op-form-grid">
                            <x-ds.input label="Name" name="name" id="cat-name" :value="old('name', $selected->name)" required maxlength="120" />
                            <x-ds.input label="Web address" name="slug" id="cat-slug" :value="old('slug', $selected->slug)" required hint="Lowercase letters, numbers and dashes. Changing it breaks old links." />
                            <x-ds.select label="Sits under" name="parent_id" id="cat-parent" :options="$nodes->reject(fn ($n) => str_starts_with((string) $n->path, (string) $selected->path))->mapWithKeys(fn ($n) => [$n->id => str_repeat('- ', $n->depth).$n->name])->all()" :selected="old('parent_id', $selected->parent_id)" placeholder="Top level" />
                            <x-ds.input label="Order" name="sort_order" id="cat-order" type="number" min="0" max="9999" :value="old('sort_order', $selected->sort_order)" hint="Lowest first, then by name." />
                        </div>
                        <x-ds.checkbox name="is_active" id="cat-active" value="1" label="Shown in the shop" :checked="(bool) old('is_active', $selected->is_active)" hint="Untick to hide it from menus and category pages." />
                        <div><x-ds.button type="submit">Save category</x-ds.button></div>
                    </form>
                </section>

                <section class="op-block" aria-labelledby="attr-h">
                    <h2 id="attr-h">Attributes sellers fill in</h2>
                    <p class="op-muted">Shared with every category inside this one. Values already saved on listings are kept if you remove an attribute.</p>
                    @if ($selected->attributeDefinitions->isEmpty())
                        <p class="op-muted">None defined for this category.</p>
                    @else
                        <x-ds.table caption="Attributes of {{ $selected->name }}">
                            <x-slot:head><tr><th scope="col">Label</th><th scope="col">Key</th><th scope="col">Kind</th><th scope="col">Filter</th><th scope="col">Required</th><th scope="col"><span class="ds-visually-hidden">Actions</span></th></tr></x-slot:head>
                            @foreach ($selected->attributeDefinitions as $a)
                                <tr>
                                    <td data-label="Label">{{ $a->label }}{{ $a->unit ? ' ('.$a->unit.')' : '' }}</td>
                                    <td data-label="Key"><span class="op-code">{{ $a->key }}</span></td>
                                    <td data-label="Kind">{{ ['text' => 'Text', 'number' => 'Number', 'enum' => 'Choice: '.implode(', ', (array) $a->options), 'boolean' => 'Yes or no'][$a->type] ?? $a->type }}</td>
                                    <td data-label="Filter">{{ $a->is_facetable ? 'Yes' : 'No' }}</td>
                                    <td data-label="Required">{{ $a->is_required ? 'Yes' : 'No' }}</td>
                                    <td data-label="Actions"><form method="POST" action="{{ route('operator.catalogue.attributes.destroy', [$selected, $a]) }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="quiet" size="sm" aria-label="Remove the {{ $a->label }} attribute">Remove</x-ds.button></form></td>
                                </tr>
                            @endforeach
                        </x-ds.table>
                    @endif
                    <details class="op-block">
                        <summary class="ds-btn ds-btn--quiet ds-btn--sm">Add an attribute</summary>
                        <form method="POST" action="{{ route('operator.catalogue.attributes.store', $selected) }}" class="ds-stack">
                            @csrf
                            <div class="op-form-grid">
                                <x-ds.input label="Label" name="label" id="attr-label" required maxlength="100" hint="What sellers and shoppers read, such as Screen size." />
                                <x-ds.input label="Key" name="key" id="attr-key" required maxlength="60" hint="Lowercase with underscores, such as screen_size. Cannot change later." />
                                <x-ds.select label="Kind" name="type" id="attr-type" :options="['text' => 'Text', 'number' => 'Number', 'enum' => 'Choice from a list', 'boolean' => 'Yes or no']" :selected="old('type', 'text')" />
                                <x-ds.input label="Unit (optional)" name="unit" id="attr-unit" maxlength="20" hint="Such as GB or inch." />
                                <x-ds.textarea class="op-span" label="Choices" name="options" id="attr-options" rows="2" hint="Only for a choice attribute. Separate with commas." />
                                <x-ds.checkbox name="is_facetable" id="attr-facet" value="1" label="Let shoppers filter by it" />
                                <x-ds.checkbox name="is_required" id="attr-required" value="1" label="Sellers must fill it in" />
                            </div>
                            <div><x-ds.button type="submit">Add attribute</x-ds.button></div>
                        </form>
                    </details>
                </section>
            @else
                <x-ds.empty-state title="Choose a category" icon="grid">Pick one from the tree to rename it, move it, hide it or edit its attributes. Add a new one below.</x-ds.empty-state>
            @endif

            <section class="op-block" aria-labelledby="new-h">
                <h2 id="new-h">Add a category</h2>
                <form method="POST" action="{{ route('operator.catalogue.categories.store') }}" class="ds-stack">
                    @csrf
                    <div class="op-form-grid">
                        <x-ds.input label="Name" name="name" id="new-name" required maxlength="120" :value="old('name')" />
                        <x-ds.select label="Sits under" name="parent_id" id="new-parent" :options="$nodes->mapWithKeys(fn ($n) => [$n->id => str_repeat('- ', $n->depth).$n->name])->all()" :selected="$selected?->id" placeholder="Top level" />
                    </div>
                    <div><x-ds.button type="submit" variant="quiet">Add category</x-ds.button></div>
                </form>
            </section>
        </div>

        <aside class="op-side" aria-label="Category tree">
            <section class="op-block">
                <h2>The tree</h2>
                <ul class="op-tree">
                    @foreach ($nodes as $n)
                        <li data-depth="{{ min($n->depth, 4) }}" @if (! $n->is_active) data-hidden @endif>
                            <a href="{{ route('operator.catalogue.categories', ['category' => $n->id]) }}" @if ($selected && $selected->id === $n->id) aria-current="page" @endif>
                                <span>{{ $n->name }}@unless ($n->is_active) <span class="op-muted">(hidden)</span>@endunless</span>
                                <span class="op-tree__n">{{ $counts[$n->id]['rollup'] ?? 0 }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <p class="op-muted">The number is the listings a shopper can buy in that category and the ones inside it.</p>
            </section>
        </aside>
    </div>
</x-operator-layout>
