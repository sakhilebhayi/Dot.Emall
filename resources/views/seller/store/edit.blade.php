@php use App\Enums\StoreStatus; @endphp
<x-seller-layout>
    <x-slot:title>Store profile</x-slot:title>
    <x-slot:heading>Store profile</x-slot:heading>
    <x-slot:lead>How your store looks to shoppers, and the details Emall holds about your business.</x-slot:lead>
    <x-slot:actions>
        <x-ds.button variant="quiet" :href="route('seller.store.status')" icon="check-circle">Status and checklist</x-ds.button>
        @if ($store->is_active && Route::has('stores.show'))<x-ds.button variant="quiet" :href="emall_route('stores.show', $store)" icon="external">Preview public page</x-ds.button>@endif
    </x-slot:actions>

    <x-seller.errors :only="['status']" />

    <form method="POST" action="{{ route('seller.store.update') }}" enctype="multipart/form-data" class="sd-form" novalidate>
        @csrf
        @method('PATCH')

        <section class="sd-form__section" id="identity" aria-labelledby="e-identity">
            <div class="sd-form__intro"><h2 id="e-identity">Identity</h2><p>The name, words and pictures at the top of your store page.</p></div>
            <div class="sd-fields">
                <x-ds.input label="Store name" name="name" :value="old('name', $store->name)" :required="true" maxlength="255" hint="Changing the name does not change your store's web address." />
                <x-ds.textarea label="About your store" name="description" rows="5" maxlength="2000" hint="Up to 2,000 characters." :value="old('description', $store->description)" />

                <div class="sd-fields sd-fields--two">
                    <div class="sd-stack sd-stack--tight">
                        <x-ds.field label="Logo" id="logo" name="logo" hint="A square picture works best. JPEG, PNG or WebP, up to 4 MB.">
                            <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp" class="ds-input" @if ($errors->has('logo')) aria-invalid="true" @endif aria-describedby="logo-hint">
                        </x-ds.field>
                        @if ($store->logo_path)
                            <div class="sd-cluster"><span class="sd-thumb sd-thumb--lg"><img src="{{ Storage::disk('public')->url($store->logo_path) }}" alt="Your current logo" width="88" height="88"></span>
                            <x-ds.checkbox label="Remove the logo" name="remove_logo" value="1" /></div>
                        @endif
                    </div>
                    <div class="sd-stack sd-stack--tight">
                        <x-ds.field label="Banner" id="banner" name="banner" hint="A wide picture, about three times wider than tall. Up to 4 MB.">
                            <input type="file" id="banner" name="banner" accept="image/jpeg,image/png,image/webp" class="ds-input" @if ($errors->has('banner')) aria-invalid="true" @endif aria-describedby="banner-hint">
                        </x-ds.field>
                        @if ($store->banner_path)
                            <div class="sd-stack sd-stack--tight"><img src="{{ Storage::disk('public')->url($store->banner_path) }}" alt="Your current banner" style="max-width: 20rem; border-radius: var(--r-photo);" loading="lazy">
                            <x-ds.checkbox label="Remove the banner" name="remove_banner" value="1" /></div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="sd-form__section" id="contact" aria-labelledby="e-contact">
            <div class="sd-form__intro"><h2 id="e-contact">Contact and location</h2><p>The Emall team uses the e-mail and phone to reach you. Shoppers see the province and city only.</p></div>
            <div class="sd-fields sd-fields--two">
                <x-ds.input label="Contact e-mail" name="contact_email" type="email" :value="old('contact_email', $store->contact_email)" autocomplete="email" />
                <x-ds.input label="Contact phone" name="contact_phone" type="tel" :value="old('contact_phone', $store->contact_phone)" autocomplete="tel" inputmode="tel" />
                <x-ds.select label="Province you ship from" name="region" :options="array_combine($provinces, $provinces)" :selected="old('region', $store->region)" placeholder="Choose a province" />
                <x-ds.input label="City or town" name="city" :value="old('city', $store->city)" autocomplete="address-level2" maxlength="100" />
            </div>
        </section>

        <section class="sd-form__section" id="business" aria-labelledby="e-business">
            <div class="sd-form__intro"><h2 id="e-business">Trading details</h2><p>These numbers are claims you make. Emall shows them labelled as claimed by you and does not check them against any register.</p></div>
            <div class="sd-fields sd-fields--two">
                <div class="sd-span"><x-ds.input label="Legal or trading name" name="legal_name" :value="old('legal_name', $store->legal_name)" maxlength="190" hint="The registered name of the business, if it differs from your store name." /></div>
                <x-ds.input label="Company registration number (claimed)" name="registration_number" :value="old('registration_number', $store->registration_number)" maxlength="60" />
                <x-ds.input label="VAT number (claimed)" name="vat_number" :value="old('vat_number', $store->vat_number)" maxlength="40" />
                <div class="sd-span">
                    <input type="hidden" name="vat_registered" value="0">
                    <x-ds.toggle label="We are registered for VAT" name="vat_registered" :checked="(bool) old('vat_registered', $store->vat_registered)" />
                    <p class="sd-quiet">Prices on Emall include VAT. If you are registered, your order documents show the VAT part of each price at {{ rtrim(rtrim(number_format((float) config('emall.vat.rate') * 100, 2), '0'), '.') }}%. Nothing is added on top.</p>
                </div>
            </div>
        </section>

        <section class="sd-form__section" id="policies" aria-labelledby="e-policies">
            <div class="sd-form__intro"><h2 id="e-policies">Policies</h2><p>Shown on your store page and beside your listings.</p></div>
            <div class="sd-fields">
                <x-ds.textarea label="Shipping policy" name="shipping_policy" rows="4" maxlength="4000" hint="How and when you send things, and what a buyer should expect." :value="old('shipping_policy', $store->shipping_policy)" />
                <x-ds.textarea label="Returns policy" name="return_policy" rows="4" maxlength="4000" :hint="'Buyers can ask to return an item for '.(int) config('emall.returns.window_days', 7).' days after it arrives; say anything you add to that.'" :value="old('return_policy', $store->return_policy)" />
            </div>
        </section>

        <section class="sd-form__section" id="open" aria-labelledby="e-open">
            <div class="sd-form__intro"><h2 id="e-open">Open or closed</h2><p>Close your store when you are away. Nothing is deleted.</p></div>
            <div class="sd-fields">
                @if ($store->status === StoreStatus::Active)
                    <input type="hidden" name="is_active" value="0">
                    <x-ds.toggle label="My store is open to shoppers" name="is_active" :checked="(bool) old('is_active', $store->is_active)" />
                    <p class="sd-quiet">While closed, your listings are hidden and shoppers cannot buy from you. Orders already placed still need handling.</p>
                @else
                    <p class="sd-muted">Your store is {{ $store->status === StoreStatus::Suspended ? 'suspended' : ($store->status === StoreStatus::PendingReview ? 'waiting for review' : 'a draft') }}, so it is closed until the Emall team has decided. <a href="{{ route('seller.store.status') }}">See why</a>.</p>
                @endif
            </div>
        </section>

        <div class="sd-sticky-actions sd-actions">
            <x-ds.button type="submit" variant="primary">Save store profile</x-ds.button>
            <a class="ds-btn ds-btn--link" href="{{ route('seller.store.status') }}">Cancel</a>
        </div>
    </form>

    <section class="sd-form" aria-labelledby="e-company" id="company" style="margin-block-start: var(--s-6);">
        <div class="sd-form__section">
            <div class="sd-form__intro"><h2 id="e-company">InfoDot company</h2><p>Shown to shoppers as checked by InfoDot sign-in, not as a verified business.</p></div>
            <div class="sd-fields">
                @if ($store->team && filled($store->team->infodot_team_uuid))
                    <p><x-ds.badge tone="pos" icon="check-circle">Linked</x-ds.badge> <strong>{{ $store->team->name }}</strong> is your InfoDot company.</p>
                    @if ($store->team->infodot_verified_at)<p class="sd-muted">InfoDot has marked this company as verified.</p>@else<p class="sd-muted">InfoDot has not marked this company as verified, so shoppers see it as linked only.</p>@endif
                @else
                    <p class="sd-muted"><x-ds.badge tone="neutral" icon="minus-circle">Not linked</x-ds.badge> No company is linked to your store yet.</p>
                @endif
                @if ($companies->isNotEmpty())
                    <form method="POST" action="{{ route('seller.store.link-team') }}" class="sd-stack">
                        @csrf
                        <x-ds.select label="Link a company" name="team_id" :options="$companies->pluck('name', 'id')->all()" :selected="$store->team_id" placeholder="Choose a company" />
                        <div><x-ds.button type="submit" variant="quiet">Link this company</x-ds.button></div>
                    </form>
                @else
                    <details class="sd-stack sd-stack--tight"><summary class="ds-btn ds-btn--link" style="cursor: pointer;">How do I link a company?</summary>
                        <p class="sd-muted sd-measure">Companies reach Emall from InfoDot when you sign in. None came with your sign-in, so add or join your company in InfoDot, then sign out here and sign in with InfoDot again. Any company you belong to will then appear in this list.</p>
                    </details>
                @endif
            </div>
        </div>
    </section>
</x-seller-layout>
