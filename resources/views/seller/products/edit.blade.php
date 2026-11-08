@php
    use App\Support\Shop;
    $options = $product->options;
    $imgs = $product->images;
    $cover = $imgs->first();
    $missingRequired = $definitions->where('is_required', true)->filter(fn ($d) => ! $values->has($d->key));
    $rows = old('options') ?? $options->map(fn ($o) => ['name' => $o->name, 'values' => implode(', ', $o->values)])->all();
    $rows = array_pad(array_values($rows), 3, ['name' => '', 'values' => '']);
@endphp
<x-seller-layout>
    <x-slot:title>Edit {{ $product->name }}</x-slot:title>
    <x-slot:heading>{{ $product->name }}</x-slot:heading>
    <x-slot:lead>Details, photos, specifications and options for this listing.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Products', 'href' => route('seller.products.index')], ['label' => $product->name]]" /></x-slot:breadcrumbs>
    <x-slot:actions>
        @if ($product->status === 'approved' && $product->is_active)<x-ds.button variant="quiet" :href="route('products.show', $product)" icon="external">View in the shop</x-ds.button>@endif
        <form method="POST" action="{{ route('seller.products.duplicate', $product) }}">@csrf<x-ds.button type="submit" variant="quiet" icon="copy">Duplicate</x-ds.button></form>
    </x-slot:actions>

    <div class="sd-page">
        <x-seller.errors :only="['status']" />

        <section aria-labelledby="p-state" class="sd-stack">
            <h2 id="p-state" class="sd-h2">Where this listing stands</h2>
            <div class="sd-status-line">
                @if ($product->status === 'approved')<x-ds.badge tone="pos" icon="check-circle">Approved by the Emall team</x-ds.badge>
                @elseif ($product->status === 'rejected')<x-ds.status kind="product" state="rejected" />
                @else<x-ds.status kind="product" state="pending_review" />@endif
                @if ($product->is_active)<span class="sd-muted">Switched on</span>@else<x-ds.status kind="product" state="unlisted" />@endif
                <form method="POST" action="{{ route('seller.products.toggle-active', $product) }}">@csrf @method('PATCH')<button class="ds-btn ds-btn--link" type="submit">{{ $product->is_active ? 'Switch off' : 'Switch on' }}</button></form>
            </div>
            @if ($product->status === 'rejected')
                <x-ds.alert tone="crit" title="Why it was rejected">{{ $product->rejected_reason ?: 'The Emall team did not record a reason. Contact them and ask for one.' }}</x-ds.alert>
                <form method="POST" action="{{ route('seller.products.resubmit', $product) }}" class="sd-stack sd-stack--tight">@csrf
                    <p class="sd-muted sd-measure">Fix what the reason says in the sections below, then send the listing for review again.</p>
                    <div><x-ds.button type="submit" variant="primary">Send for review again</x-ds.button></div>
                </form>
            @elseif ($product->status === 'pending_approval')
                <p class="sd-muted sd-measure">The Emall team has not looked at this listing yet. You can keep editing it. Shoppers cannot see it until it is approved.</p>
            @endif
            @if ($missingRequired->isNotEmpty())<x-ds.alert tone="cau" title="Specifications still needed">{{ $missingRequired->pluck('label')->implode(', ') }} {{ $missingRequired->count() === 1 ? 'is' : 'are' }} required for this category. <a href="#specs">Add {{ $missingRequired->count() === 1 ? 'it' : 'them' }}</a>.</x-ds.alert>@endif
        </section>

        <form method="POST" action="{{ route('seller.products.update', $product) }}" class="sd-form" novalidate data-fee-percent="{{ $fee['percent'] }}">
            @csrf @method('PATCH')
            <input type="hidden" name="stock_was" value="{{ $product->stock }}">
            <section class="sd-form__section" id="details" aria-labelledby="p-details">
                <div class="sd-form__intro"><h2 id="p-details">Details</h2><p>Shoppers search on the name, brand and category.</p></div>
                <div class="sd-fields">
                    <x-ds.input label="Product name" name="name" :value="old('name', $product->name)" :required="true" maxlength="255" />
                    <div class="sd-fields sd-fields--two">
                        <x-ds.select label="Category" name="category_id" :options="$categories" :selected="old('category_id', $product->category_id)" placeholder="No category" hint="Changing it changes which specifications you are asked for." />
                        <x-ds.input label="Brand" name="brand" list="brand-list" :value="old('brand', $product->brand?->name)" maxlength="100" />
                    </div>
                    <datalist id="brand-list">@foreach ($brands as $b)<option value="{{ $b }}"></option>@endforeach</datalist>
                    <x-ds.textarea label="Description" name="description" rows="6" maxlength="2000" :value="old('description', $product->description)" />
                </div>
            </section>

            <section class="sd-form__section" id="pricing" aria-labelledby="p-price">
                <div class="sd-form__intro"><h2 id="p-price">Price and stock</h2><p>Prices include VAT. Emall never shows a "was" price you type: a reduced price appears only when the price really dropped in the last 30 days.</p></div>
                <div class="sd-fields sd-fields--two">
                    @if ($simple)
                        <x-ds.input label="Price (rand)" name="price" type="text" inputmode="decimal" :value="old('price', number_format((float) $product->price, 2, '.', ''))" :required="true" data-fee-price autocomplete="off" />
                        <x-ds.input label="In stock" name="stock" type="number" inputmode="numeric" min="0" :value="old('stock', $product->stock)" :required="true" hint="If buyers buy some while you edit, your change is applied to the new number." />
                        <div class="sd-span sd-fee" aria-live="polite"><strong>Emall's fee</strong><span data-fee-out data-empty="Enter a price to see what you receive.">{{ $fee['sentence'] }}</span>
                            <span class="sd-quiet">{{ \App\Services\Ledger\SellerFees::text($store) }}</span></div>
                        <x-ds.input label="SKU" name="sku" :value="old('sku', $product->sku)" maxlength="100" hint="Your own code. Unique across Emall." />
                    @else
                        <input type="hidden" name="price" value="{{ number_format((float) $product->price, 2, '.', '') }}">
                        <input type="hidden" name="stock" value="{{ $product->stock }}">
                        <div class="sd-span sd-stack sd-stack--tight">
                            <p class="sd-muted">This product has options, so the price, stock and SKU are set for each one in <a href="#variants">Options and variants</a>. Shoppers see "from {{ Shop::amount($product->price) }}" and {{ $product->stock }} in stock in total.</p>
                            <div class="sd-fee" aria-live="polite"><strong>Emall's fee</strong><span>{{ \App\Services\Ledger\SellerFees::text($store) }}</span></div>
                        </div>
                    @endif
                    <x-ds.input label="Warn me when stock is at or below" name="low_stock_threshold" type="number" inputmode="numeric" min="0" :value="old('low_stock_threshold', $product->low_stock_threshold)" />
                </div>
            </section>

            <div class="sd-sticky-actions sd-actions">
                <x-ds.button type="submit" variant="primary">Save details</x-ds.button>
                <a class="ds-btn ds-btn--link" href="{{ route('seller.products.stock-history', $product) }}">Stock history</a>
            </div>
        </form>

        <section class="sd-form__section" id="photos" aria-labelledby="p-photos" style="border-top: 1px solid var(--line);">
            <div class="sd-form__intro"><h2 id="p-photos">Photos</h2><p>Up to {{ \App\Services\ProductImages::MAX_PER_PRODUCT }}. The cover is the one shoppers see first. {{ $imgs->count() }} of {{ \App\Services\ProductImages::MAX_PER_PRODUCT }} used.</p></div>
            <div class="sd-fields">
                @if ($imgs->isEmpty())<p class="sd-muted">No photos yet. Listings with a photo are far easier to trust, and the Emall team can reject one without.</p>@endif
                <ul class="sd-photos">
                    @foreach ($imgs as $i => $img)
                        <li class="sd-photo">
                            <div class="sd-photo__frame"><img src="{{ $img->url() }}" alt="Photo {{ $i + 1 }} of {{ $product->name }}" loading="lazy">@if ($i === 0)<x-ds.badge tone="ink" class="sd-photo__tag">Cover</x-ds.badge>@endif</div>
                            <div class="sd-cluster">
                                @if ($i !== 0)<form method="POST" action="{{ route('seller.products.images.cover', [$product, $img]) }}">@csrf @method('PATCH')<button class="ds-btn ds-btn--quiet ds-btn--sm" type="submit">Make cover</button></form>@endif
                                <button type="button" class="ds-btn ds-btn--danger ds-btn--sm" data-dialog-open="rm-photo-{{ $img->id }}">Remove</button>
                            </div>
                            <x-ds.confirm-dialog id="rm-photo-{{ $img->id }}" title="Remove this photo?" :action="route('seller.products.images.destroy', [$product, $img])" method="DELETE" confirmLabel="Remove photo">The photo is deleted from the listing and cannot be brought back.</x-ds.confirm-dialog>
                        </li>
                    @endforeach
                </ul>
                @if ($imgs->count() < \App\Services\ProductImages::MAX_PER_PRODUCT)
                    <form method="POST" action="{{ route('seller.products.images.store', $product) }}" enctype="multipart/form-data" data-upload class="sd-stack">
                        @csrf
                        <div class="sd-drop" data-drop data-max="{{ \App\Services\ProductImages::MAX_PER_PRODUCT - $imgs->count() }}">
                            <x-ds.icon name="upload" :size="28" />
                            <label for="more-images"><strong>Choose pictures</strong> or drop them here</label>
                            <input type="file" id="more-images" name="images[]" multiple accept="image/jpeg,image/png,image/webp">
                            <ul class="sd-drop__files" data-drop-files></ul>
                            <p class="sd-quiet" data-drop-live aria-live="polite"></p>
                        </div>
                        <div class="sd-progress" data-progress hidden role="progressbar" aria-label="Upload progress"><span></span></div>
                        <p class="ds-field__error" data-upload-message role="alert" hidden></p>
                        @error('images')<p class="ds-field__error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>@enderror
                        <div><x-ds.button type="submit" variant="quiet" icon="upload">Add photos</x-ds.button></div>
                    </form>
                @endif
            </div>
        </section>

        <section class="sd-form__section" id="specs" aria-labelledby="p-specs" style="border-top: 1px solid var(--line);">
            <div class="sd-form__intro"><h2 id="p-specs">Specifications</h2><p>Shoppers filter by these. Fill in what applies; a * means the category requires it.</p></div>
            <div class="sd-fields">
                @if (! $product->category)
                    <p class="sd-muted">Choose a category above and save to see which specifications apply to this product.</p>
                @elseif ($definitions->isEmpty())
                    <p class="sd-muted">{{ $product->category->name }} does not ask for any specifications.</p>
                @else
                    <form method="POST" action="{{ route('seller.products.attributes', $product) }}" class="sd-stack" novalidate>
                        @csrf @method('PATCH')
                        <div class="sd-fields sd-fields--two">
                            @foreach ($definitions as $key => $d)
                                @php $val = old("attributes.$key", $values[$key]->value ?? ''); $hintBits = array_filter([$d->unit ? 'In '.$d->unit : null, $d->is_facetable ? 'Shoppers can filter by this' : null]); @endphp
                                @if ($d->type === 'enum')
                                    <x-ds.select :label="$d->label" :name="'attributes['.$key.']'" :id="'attr-'.$key" :options="array_combine((array) $d->options, (array) $d->options)" :selected="$val" placeholder="Not set" :required="$d->is_required" :hint="implode('. ', $hintBits) ?: null" :error="$errors->first('attributes.'.$key)" />
                                @elseif ($d->type === 'boolean')
                                    <x-ds.select :label="$d->label" :name="'attributes['.$key.']'" :id="'attr-'.$key" :options="['Yes' => 'Yes', 'No' => 'No']" :selected="$val" placeholder="Not set" :required="$d->is_required" :error="$errors->first('attributes.'.$key)" />
                                @else
                                    <x-ds.input :label="$d->label" :name="'attributes['.$key.']'" :id="'attr-'.$key" :type="$d->type === 'number' ? 'text' : 'text'" :inputmode="$d->type === 'number' ? 'decimal' : null" :value="$val" :required="$d->is_required" :hint="implode('. ', $hintBits) ?: null" :error="$errors->first('attributes.'.$key)" maxlength="255" />
                                @endif
                            @endforeach
                        </div>
                        <div><x-ds.button type="submit" variant="quiet">Save specifications</x-ds.button></div>
                    </form>
                @endif
            </div>
        </section>

        <section class="sd-form__section" id="variants" aria-labelledby="p-variants" style="border-top: 1px solid var(--line);">
            <div class="sd-form__intro"><h2 id="p-variants">Options and variants</h2><p>For things that come in sizes, colours or packs. Each combination gets its own price, stock and SKU.</p></div>
            <div class="sd-fields">
                <form method="POST" action="{{ route('seller.products.options', $product) }}" class="sd-stack" novalidate>
                    @csrf
                    <x-seller.errors :only="['options']" />
                    <p class="sd-muted sd-measure">Name an option and list its values with commas, for example Size: S, M, L. Up to {{ \App\Services\Catalogue\ProductVariants::MAX_OPTIONS }} options. Leave all empty for a product with one price.</p>
                    @foreach ($rows as $i => $row)
                        <div class="sd-fields sd-fields--two" style="grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);">
                            <x-ds.input :label="'Option '.($i + 1).' name'" :name="'options['.$i.'][name]'" :id="'opt-name-'.$i" :value="$row['name']" maxlength="40" :placeholder="['Size', 'Colour', 'Material'][$i] ?? ''" />
                            <x-ds.input :label="'Option '.($i + 1).' values'" :name="'options['.$i.'][values]'" :id="'opt-values-'.$i" :value="$row['values']" maxlength="1000" :placeholder="['S, M, L', 'Red, Blue', 'Cotton, Wool'][$i] ?? ''" />
                        </div>
                    @endforeach
                    <div class="sd-actions">
                        <x-ds.button type="submit" variant="quiet">Save options</x-ds.button>
                        @if ($options->isNotEmpty())<p class="sd-quiet">Removing a value switches off its combinations. Empty every row and save to go back to one price.</p>@endif
                    </div>
                </form>

                @if (! $simple)
                    <form method="POST" action="{{ route('seller.products.variants', $product) }}" class="sd-stack" novalidate>
                        @csrf
                        <h3 class="sd-h3">Combinations</h3>
                        <p class="sd-muted sd-measure">A combination with no price is not sold. The price shown on the listing is the cheapest one that is switched on.</p>
                        <table class="sd-variants">
                            <thead><tr><th scope="col">Combination</th><th scope="col">Price (rand)</th><th scope="col">Stock</th><th scope="col">SKU</th><th scope="col">Photo</th><th scope="col">On</th></tr></thead>
                            <tbody>
                            @foreach ($combos as $i => $combo)
                                @php $v = $combo['variant']; $label = collect($combo['choices'])->map(fn ($val, $name) => "$name: $val")->implode(', '); @endphp
                                <tr>
                                    <td class="sd-full" data-label="Combination">
                                        {{ $label }}
                                        @foreach ($combo['choices'] as $name => $val)<input type="hidden" name="rows[{{ $i }}][options][{{ $name }}]" value="{{ $val }}">@endforeach
                                        @if ($v)<input type="hidden" name="rows[{{ $i }}][stock_was]" value="{{ $v->stock }}">@endif
                                    </td>
                                    <td class="sd-w-price" data-label="Price (rand)">
                                        <input class="ds-input" type="text" inputmode="decimal" name="rows[{{ $i }}][price]" value="{{ old("rows.$i.price", $v ? number_format((float) $v->price, 2, '.', '') : '') }}" aria-label="Price for {{ $label }}" @if ($errors->has("rows.$i.price")) aria-invalid="true" @endif autocomplete="off">
                                        @if ($errors->has("rows.$i.price"))<p class="ds-field__error">{{ $errors->first("rows.$i.price") }}</p>@endif
                                    </td>
                                    <td class="sd-w-stock" data-label="Stock"><input class="ds-input" type="number" min="0" inputmode="numeric" name="rows[{{ $i }}][stock]" value="{{ old("rows.$i.stock", $v?->stock) }}" aria-label="Stock for {{ $label }}">@if ($errors->has("rows.$i.stock"))<p class="ds-field__error">{{ $errors->first("rows.$i.stock") }}</p>@endif</td>
                                    <td data-label="SKU"><input class="ds-input" type="text" name="rows[{{ $i }}][sku]" value="{{ old("rows.$i.sku", $v?->sku) }}" aria-label="SKU for {{ $label }}" maxlength="100">@if ($errors->has("rows.$i.sku"))<p class="ds-field__error">{{ $errors->first("rows.$i.sku") }}</p>@endif</td>
                                    <td data-label="Photo">
                                        <select class="ds-select" name="rows[{{ $i }}][image_id]" aria-label="Photo for {{ $label }}">
                                            <option value="">Cover photo</option>
                                            @foreach ($imgs as $k => $img)<option value="{{ $img->id }}" @selected((string) old("rows.$i.image_id", $v?->image_id) === (string) $img->id)>Photo {{ $k + 1 }}</option>@endforeach
                                        </select>
                                    </td>
                                    <td data-label="Switched on"><label class="ds-choice" style="min-height: 2.5rem;"><input type="checkbox" name="rows[{{ $i }}][is_active]" value="1" @checked(old("rows.$i.is_active", $v ? $v->is_active : true)) aria-label="Sell {{ $label }}"><span class="ds-choice__text sd-sr"><span class="ds-choice__label">On</span></span></label></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <div><x-ds.button type="submit" variant="primary">Save combinations</x-ds.button></div>
                    </form>
                @endif
            </div>
        </section>

        <section class="sd-form__section" id="preview" aria-labelledby="p-preview" style="border-top: 1px solid var(--line);">
            <div class="sd-form__intro"><h2 id="p-preview">Preview</h2><p>How a shopper sees this listing in a search result.</p></div>
            <div class="sd-fields" style="max-width: 16rem;">
                <x-ds.product-card :href="route('products.show', $product)" :title="$product->name" :image="$cover?->url()" :store="$store->name" :amount="(string) $product->price" :stock="$product->stock" :state="$product->status === 'approved' && $product->is_active ? null : ($product->status === 'approved' ? 'unlisted' : ($product->status === 'rejected' ? 'rejected' : 'pending_review'))" />
                <p class="sd-quiet">The link works for you only once the listing is approved and switched on.</p>
            </div>
        </section>

        <section class="sd-form__section" id="remove" aria-labelledby="p-remove" style="border-top: 1px solid var(--line);">
            <div class="sd-form__intro"><h2 id="p-remove">Delete</h2><p>Takes the listing out of the shop for good.</p></div>
            <div class="sd-fields"><div><button type="button" class="ds-btn ds-btn--danger" data-dialog-open="rm-product">Delete this product</button></div></div>
            <x-ds.confirm-dialog id="rm-product" title="Delete {{ $product->name }}?" :action="route('seller.products.destroy', $product)" method="DELETE" confirmLabel="Delete product">The listing leaves the shop and every cart. Orders already placed keep their history. This cannot be undone.</x-ds.confirm-dialog>
        </section>
    </div>
</x-seller-layout>
