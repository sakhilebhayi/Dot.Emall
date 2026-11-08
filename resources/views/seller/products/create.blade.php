<x-seller-layout>
    <x-slot:title>New product</x-slot:title>
    <x-slot:heading>New product</x-slot:heading>
    <x-slot:lead>Start with the basics. You add options such as size and colour, specifications and more photos on the next page.</x-slot:lead>

    <x-seller.errors :only="['status']" />
    <form method="POST" action="{{ route('seller.products.store') }}" enctype="multipart/form-data" class="sd-form" novalidate data-fee-percent="{{ $fee['percent'] }}">
        @csrf
        <section class="sd-form__section" aria-labelledby="n-what">
            <div class="sd-form__intro"><h2 id="n-what">What it is</h2><p>Shoppers search on the name, brand and category, so be specific.</p></div>
            <div class="sd-fields">
                <x-ds.input label="Product name" name="name" :required="true" maxlength="255" />
                <div class="sd-fields sd-fields--two">
                    <x-ds.select label="Category" name="category_id" :options="$categories" placeholder="Choose a category" hint="This decides which specifications you are asked for." />
                    <x-ds.input label="Brand" name="brand" list="brand-list" maxlength="100" hint="Optional. Start typing to reuse a brand." />
                </div>
                <datalist id="brand-list">@foreach ($brands as $b)<option value="{{ $b }}"></option>@endforeach</datalist>
                <x-ds.textarea label="Description" name="description" rows="5" maxlength="2000" hint="Up to 2,000 characters. Say what is in the box and what the item is made of." />
            </div>
        </section>

        <section class="sd-form__section" aria-labelledby="n-price">
            <div class="sd-form__intro"><h2 id="n-price">Price and stock</h2><p>Prices include VAT. If this comes in sizes or colours, set one price now and add the options next.</p></div>
            <div class="sd-fields sd-fields--two">
                <x-ds.input label="Price (rand)" name="price" type="text" inputmode="decimal" :required="true" data-fee-price autocomplete="off" hint="For example 249.99" />
                <x-ds.input label="How many you have" name="stock" type="number" inputmode="numeric" min="0" :required="true" :value="old('stock', 1)" />
                <div class="sd-span sd-fee" aria-live="polite"><strong>Emall's fee</strong><span data-fee-out data-empty="Enter a price to see what you receive.">{{ $fee['percent'] <= 0 ? 'Enter a price to see what you receive.' : 'Enter a price to see what you receive after Emall\'s fee.' }}</span>
                    <span class="sd-quiet">{{ \App\Services\Ledger\SellerFees::text($store) }}</span></div>
                <x-ds.input label="SKU" name="sku" maxlength="100" hint="Your own code for this product. Optional, and unique." />
                <x-ds.input label="Warn me when stock is at or below" name="low_stock_threshold" type="number" inputmode="numeric" min="0" :value="old('low_stock_threshold', 5)" />
            </div>
        </section>

        <section class="sd-form__section" aria-labelledby="n-photos">
            <div class="sd-form__intro"><h2 id="n-photos">Photos</h2><p>Up to {{ \App\Services\ProductImages::MAX_PER_PRODUCT }}. The first is the cover. JPEG, PNG or WebP, at least 100 pixels, up to 4 MB each.</p></div>
            <div class="sd-fields">
                <div class="sd-drop" data-drop data-max="{{ \App\Services\ProductImages::MAX_PER_PRODUCT }}">
                    <x-ds.icon name="upload" :size="28" />
                    <label for="images"><strong>Choose pictures</strong> or drop them here</label>
                    <input type="file" id="images" name="images[]" multiple accept="image/jpeg,image/png,image/webp">
                    <ul class="sd-drop__files" data-drop-files></ul>
                    <p class="sd-quiet" data-drop-live aria-live="polite"></p>
                </div>
                @error('images')<p class="ds-field__error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $message }}</span></p>@enderror
                @foreach ($errors->get('images.*') as $messages)<p class="ds-field__error"><x-ds.icon name="alert-triangle" :size="16" /><span>{{ $messages[0] }}</span></p>@endforeach
            </div>
        </section>

        <div class="sd-sticky-actions sd-actions">
            <x-ds.button type="submit" variant="primary" size="lg">Save and send for review</x-ds.button>
            <a class="ds-btn ds-btn--link" href="{{ route('seller.products.index') }}">Cancel</a>
        </div>
    </form>
</x-seller-layout>
