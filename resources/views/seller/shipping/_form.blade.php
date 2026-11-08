{{-- One shipping method's fields. Variables: $method (ShippingMethod or null), $types, $prefix (unique id prefix). Only the form that was submitted gets the old values and the errors back. --}}
@php
    $m = $method;
    $mine = (string) old('_method_id', '') === (string) ($m?->id ?? 'new');
    $money = fn ($x) => $x === null ? '' : number_format((float) $x, 2, '.', '');
    $stored = [
        'name' => $m?->name, 'type' => $m?->type?->value ?? 'flat', 'price' => $money($m?->price), 'free_over' => $money($m?->free_over),
        'handling_days' => $m?->handling_days ?? 1, 'window_min_days' => $m?->window_min_days ?? 2, 'window_max_days' => $m?->window_max_days ?? 5,
        'collection_address' => $m?->collection_address, 'instructions' => $m?->instructions,
    ];
    $v = fn (string $k) => $mine ? old($k, $stored[$k]) : $stored[$k];
    $err = fn (string $k) => $mine ? $errors->first($k) : null;
    $active = $mine ? (bool) old('is_active') : ($m ? $m->is_active : true);
@endphp
<input type="hidden" name="_method_id" value="{{ $m?->id ?? 'new' }}">
<div class="sd-fields sd-fields--two">
    <x-ds.input label="Name shoppers see" name="name" :id="$prefix.'-name'" :value="$v('name')" :error="$err('name')" :required="true" maxlength="80" placeholder="Standard courier" />
    <x-ds.select label="Kind" name="type" :id="$prefix.'-type'" :options="collect($types)->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" :selected="$v('type')" :error="$err('type')" :required="true" />
    <x-ds.input label="Price (rand)" name="price" :id="$prefix.'-price'" inputmode="decimal" :value="$v('price')" :error="$err('price')" hint="A flat rate, or the price below the free threshold. Collection is always free." autocomplete="off" />
    <x-ds.input label="Free over (rand)" name="free_over" :id="$prefix.'-free'" inputmode="decimal" :value="$v('free_over')" :error="$err('free_over')" hint="Only for free over a threshold: the items total with you at which delivery is free." autocomplete="off" />
    <x-ds.input label="Packing time (working days)" name="handling_days" :id="$prefix.'-handling'" type="number" min="0" max="30" :value="$v('handling_days')" :error="$err('handling_days')" :required="true" hint="How long you need before you hand it to the carrier." />
    <div class="sd-fields sd-fields--two" style="gap: var(--s-3);">
        <x-ds.input label="Fastest delivery (days)" name="window_min_days" :id="$prefix.'-wmin'" type="number" min="0" max="60" :value="$v('window_min_days')" :error="$err('window_min_days')" :required="true" />
        <x-ds.input label="Slowest delivery (days)" name="window_max_days" :id="$prefix.'-wmax'" type="number" min="0" max="90" :value="$v('window_max_days')" :error="$err('window_max_days')" :required="true" />
    </div>
    <div class="sd-span"><x-ds.textarea label="Collection address and hours" name="collection_address" :id="$prefix.'-addr'" rows="3" maxlength="500" :value="$v('collection_address')" :error="$err('collection_address')" hint="Only for collection: where buyers come and when you are open. Shoppers see this after they choose collection." /></div>
    <div class="sd-span"><x-ds.input label="Note for buyers" name="instructions" :id="$prefix.'-note'" :value="$v('instructions')" :error="$err('instructions')" maxlength="255" hint="Optional, for example whether a signature is needed." /></div>
    <div class="sd-span">
        <input type="hidden" name="is_active" value="0">
        <x-ds.toggle label="Offer this method to buyers" name="is_active" :id="$prefix.'-active'" :checked="$active" />
    </div>
</div>
