{{-- The typed delivery address, one field per line of a South African address. Variables: $values (array, typed or saved), $provinces. Errors are read as address.<field>. --}}
@php
    $v = fn (string $k) => old('address.'.$k, $values[$k] ?? '');
    $e = fn (string $k) => $errors->first('address.'.$k);
@endphp
<div class="co-fields">
    <x-ds.input label="Name of the person receiving it" name="address[recipient_name]" id="addr-recipient_name" :value="$v('recipient_name')" :error="$e('recipient_name')" required autocomplete="name" />
    <x-ds.input label="Street address" name="address[line1]" id="addr-line1" :value="$v('line1')" :error="$e('line1')" required autocomplete="address-line1" />
    <x-ds.input label="Apartment, unit or building (optional)" name="address[line2]" id="addr-line2" :value="$v('line2')" :error="$e('line2')" autocomplete="address-line2" />
    <x-ds.input label="Suburb (optional)" name="address[suburb]" id="addr-suburb" :value="$v('suburb')" :error="$e('suburb')" autocomplete="address-level3" />
    <div class="co-fields__pair">
        <x-ds.input label="City or town" name="address[city]" id="addr-city" :value="$v('city')" :error="$e('city')" required autocomplete="address-level2" />
        <x-ds.input label="Postal code" name="address[postal_code]" id="addr-postal_code" :value="$v('postal_code')" :error="$e('postal_code')" required inputmode="numeric" maxlength="4" autocomplete="postal-code" />
    </div>
    <x-ds.select label="Province" name="address[province]" id="addr-province" :options="array_combine($provinces, $provinces)" placeholder="Choose a province" :selected="(string) $v('province')" :error="$e('province')" required />
    <input type="hidden" name="address[country]" value="ZA">
    <x-ds.input label="Phone for the courier (optional)" name="address[phone]" id="addr-phone" type="tel" :value="$v('phone')" :error="$e('phone')" autocomplete="tel" />
    <x-ds.textarea label="Delivery instructions (optional)" name="address[instructions]" id="addr-instructions" rows="2" :value="$v('instructions')" :error="$e('instructions')" hint="For example a gate code or where to leave the parcel. The seller sees this only while they still have to send your parcel." />
</div>
