@php
    $editing ??= null;
    $values = $editing ? $editing->only(['label', 'recipient_name', 'phone', 'line1', 'line2', 'suburb', 'city', 'province', 'postal_code', 'instructions']) : [];
    $v = fn (string $k, $d = '') => old($k, $values[$k] ?? $d);
@endphp
<x-account-layout>
    <x-slot:title>Address book</x-slot:title>
    <x-slot:heading>Address book</x-slot:heading>
    <x-slot:lead>Saved delivery addresses. Checkout offers them, and an order keeps the address it was sent to even if you change one here.</x-slot:lead>

    @if ($addresses->isNotEmpty())
        <ul class="co-addresses">
            @foreach ($addresses as $a)
                <li>
                    <x-ds.address-block :card="true" :name="$a->label ? $a->label.': '.$a->recipient_name : $a->recipient_name" :lines="[\App\Models\Address::oneLine($a->snapshot())]" :phone="$a->phone" :is-default="$a->is_default_shipping">
                        <x-slot:actions>
                            <x-ds.button :href="route('account.addresses.edit', $a)" size="sm" variant="quiet" icon="edit">Edit<span class="ds-visually-hidden"> {{ $a->recipient_name }}</span></x-ds.button>
                            @unless ($a->is_default_shipping)<form method="POST" action="{{ route('account.addresses.default', $a) }}">@csrf<x-ds.button type="submit" size="sm" variant="quiet">Make default<span class="ds-visually-hidden"> {{ $a->recipient_name }}</span></x-ds.button></form>@endunless
                            <x-ds.button size="sm" variant="danger" icon="trash" data-dialog-open="del-{{ $a->id }}">Delete<span class="ds-visually-hidden"> {{ $a->recipient_name }}</span></x-ds.button>
                            <x-ds.confirm-dialog :id="'del-'.$a->id" title="Delete this address?" :action="route('account.addresses.destroy', $a)" method="DELETE" confirmLabel="Delete address">
                                <p>{{ \App\Models\Address::oneLine($a->snapshot()) }} is removed from your address book. Orders already placed keep the address they were sent to.</p>
                            </x-ds.confirm-dialog>
                        </x-slot:actions>
                    </x-ds.address-block>
                </li>
            @endforeach
        </ul>
    @endif

    <x-ds.panel :title="$editing ? 'Edit this address' : 'Add an address'" id="address-form">
        @if ($editing === null && $addresses->count() >= $max)
            <p class="ds-muted">You have the most addresses allowed ({{ $max }}). Delete one to add another.</p>
        @else
            <form method="POST" action="{{ $editing ? route('account.addresses.update', $editing) : route('account.addresses.store') }}" class="co-form">
                @csrf
                @if ($editing) @method('PATCH') @endif
                <x-ds.input label="Label (optional)" name="label" :value="$v('label')" hint="For example Home or Work." />
                <x-ds.input label="Name of the person receiving it" name="recipient_name" :value="$v('recipient_name', auth()->user()->name)" required autocomplete="name" />
                <x-ds.input label="Street address" name="line1" :value="$v('line1')" required autocomplete="address-line1" />
                <x-ds.input label="Apartment, unit or building (optional)" name="line2" :value="$v('line2')" autocomplete="address-line2" />
                <x-ds.input label="Suburb (optional)" name="suburb" :value="$v('suburb')" autocomplete="address-level3" />
                <div class="co-fields__pair">
                    <x-ds.input label="City or town" name="city" :value="$v('city')" required autocomplete="address-level2" />
                    <x-ds.input label="Postal code" name="postal_code" :value="$v('postal_code')" required inputmode="numeric" maxlength="4" autocomplete="postal-code" />
                </div>
                <x-ds.select label="Province" name="province" :options="array_combine($provinces, $provinces)" placeholder="Choose a province" :selected="(string) $v('province')" required />
                <input type="hidden" name="country" value="ZA">
                <x-ds.input label="Phone (optional)" name="phone" type="tel" :value="$v('phone')" autocomplete="tel" />
                <x-ds.textarea label="Delivery instructions (optional)" name="instructions" rows="2" :value="$v('instructions')" />
                <x-ds.checkbox label="Use as my default delivery address" name="is_default_shipping" value="1" :checked="(bool) ($editing?->is_default_shipping)" />
                <div class="co-stepact">
                    <x-ds.button type="submit" icon="check">{{ $editing ? 'Save changes' : 'Save address' }}</x-ds.button>
                    @if ($editing)<x-ds.button :href="route('account.addresses')" variant="quiet">Cancel</x-ds.button>@endif
                </div>
            </form>
        @endif
    </x-ds.panel>
</x-account-layout>
