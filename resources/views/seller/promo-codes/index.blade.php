@php use App\Support\Shop; @endphp
<x-seller-layout>
    <x-slot:title>Promotions</x-slot:title>
    <x-slot:heading>Promotions</x-slot:heading>
    <x-slot:lead>Promo codes for your store. A code discounts your items only, never another seller's.</x-slot:lead>

    <div class="sd-page">
        <x-seller.errors :only="['status']" />
        <div class="sd-cols">
            <section aria-labelledby="pc-list" class="sd-stack">
                <h2 id="pc-list" class="sd-h2">Your codes</h2>
                @if ($promoCodes->isEmpty())
                    <x-ds.empty-state title="No promo codes yet" icon="percent">Make a code to bring buyers back or to reward a first order. Buyers type it at checkout.</x-ds.empty-state>
                @else
                    <ul class="sd-list">
                        @foreach ($promoCodes as $p)
                            @php $use = $redeemed[$p->id] ?? null; $expired = $p->expires_at && $p->expires_at->isPast(); $spent = $p->max_uses !== null && $p->used_count >= $p->max_uses; @endphp
                            <li class="sd-item sd-item--nobox">
                                <div class="sd-item__main">
                                    <div class="sd-cluster"><span class="sd-code" style="font-size: var(--text-sm); font-weight: 650;">{{ $p->code }}</span>
                                        @if (! $p->is_active)<x-ds.badge tone="cau" icon="eye-off">Paused</x-ds.badge>@elseif ($expired)<x-ds.badge icon="clock">Expired</x-ds.badge>@elseif ($spent)<x-ds.badge icon="minus-circle">Used up</x-ds.badge>@else<x-ds.badge tone="pos" icon="check-circle">Working</x-ds.badge>@endif</div>
                                    <div class="sd-item__meta">
                                        <span><strong>{{ $p->discount_type === 'percent' ? rtrim(rtrim(number_format((float) $p->discount_value, 2), '0'), '.').'% off' : Shop::amount($p->discount_value).' off' }}</strong>@if ($p->free_shipping), free delivery @endif</span>
                                        @if ($p->min_spend)<span>Spend {{ Shop::amount($p->min_spend) }} or more</span>@endif
                                        @if ($p->per_user_limit)<span>{{ $p->per_user_limit }} per buyer</span>@endif
                                        <span>Used {{ $use->uses ?? 0 }}{{ $p->max_uses ? ' of '.$p->max_uses : '' }} {{ ($use->uses ?? 0) === 1 && ! $p->max_uses ? 'time' : 'times' }}@if ($use), {{ Shop::amount($use->given) }} given away @endif</span>
                                        <span>{{ $p->expires_at ? 'Ends '.$p->expires_at->format('j M Y') : 'No end date' }}</span>
                                    </div>
                                    @if ($p->conditions)<p class="sd-quiet">Conditions: {{ $p->conditions }}</p>@endif
                                </div>
                                <div class="sd-item__side">
                                    <form method="POST" action="{{ route('seller.promo-codes.toggle', $p) }}">@csrf @method('PATCH')<button type="submit" class="ds-btn ds-btn--quiet ds-btn--sm">{{ $p->is_active ? 'Pause' : 'Switch on' }}<span class="sd-sr"> {{ $p->code }}</span></button></form>
                                    <button type="button" class="ds-btn ds-btn--danger ds-btn--sm" data-dialog-open="rm-promo-{{ $p->id }}">Delete<span class="sd-sr"> {{ $p->code }}</span></button>
                                    <x-ds.confirm-dialog id="rm-promo-{{ $p->id }}" title="Delete {{ $p->code }}?" :action="route('seller.promo-codes.destroy', $p)" method="DELETE" confirmLabel="Delete code">Buyers can no longer use it. Orders that already used it keep their discount. This cannot be undone.</x-ds.confirm-dialog>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h2 class="sd-h2" style="margin-block-start: var(--s-5);">Where it was used</h2>
                @if ($usage->isEmpty())
                    <p class="sd-muted">No order has used one of your codes yet.</p>
                @else
                    <x-ds.table caption="Orders that used a code" :stack="true">
                        <x-slot:head><tr><th scope="col">Order</th><th scope="col">Code</th><th scope="col">Placed</th><th scope="col" class="is-num">Discount</th><th scope="col">Free delivery</th></tr></x-slot:head>
                        @foreach ($usage as $o)
                            <tr><td data-label="Order">{{ $o->reference() }}</td><td data-label="Code" class="sd-code">{{ $o->promo_code }}</td><td data-label="Placed">{{ $o->created_at->format('j M Y') }}</td><td data-label="Discount" class="is-num">{{ $o->money(\App\Support\Money::toCents($o->discount_amount)) }}</td><td data-label="Free delivery">{{ $o->promo_free_shipping ? 'Yes' : 'No' }}</td></tr>
                        @endforeach
                    </x-ds.table>
                    <p class="sd-quiet">Cancelled orders are left out. Buyers' names are not shown.</p>
                @endif
            </section>

            <aside aria-labelledby="pc-new" class="sd-stack">
                <h2 id="pc-new" class="sd-h2">New promo code</h2>
                <form method="POST" action="{{ route('seller.promo-codes.store') }}" class="sd-stack" novalidate>
                    @csrf
                    <x-ds.input label="Code" name="code" :required="true" maxlength="40" autocomplete="off" hint="Letters, numbers, dashes. Not case sensitive." />
                    <x-ds.select label="Kind of discount" name="discount_type" :options="['percent' => 'A percentage off', 'fixed' => 'A fixed amount off']" :selected="old('discount_type', 'percent')" :required="true" />
                    <x-ds.input label="How much (percent, or rand)" name="discount_value" inputmode="decimal" :required="true" autocomplete="off" />
                    <x-ds.input label="Minimum spend (rand)" name="min_spend" inputmode="decimal" hint="Optional. Counted on your items only." autocomplete="off" />
                    <x-ds.input label="Total uses allowed" name="max_uses" type="number" min="1" inputmode="numeric" hint="Optional. Leave empty for no limit." />
                    <x-ds.input label="Uses per buyer" name="per_user_limit" type="number" min="1" inputmode="numeric" hint="Optional. A signed-in buyer, or a guest by e-mail address." />
                    <x-ds.input label="Last day it works" name="expires_at" type="date" hint="Optional. It works until the end of that day." />
                    <div><input type="hidden" name="free_shipping" value="0"><x-ds.toggle label="Also make delivery free" name="free_shipping" /></div>
                    <x-ds.textarea label="Conditions note" name="conditions" rows="2" maxlength="500" hint="Optional, for example: Not valid on sale items. It is kept with the code and offered to the cart to show buyers." />
                    <p class="sd-quiet">Applies to everything in your store. Codes limited to one product or category are not offered yet, because the discount is shared out per store.</p>
                    <div><x-ds.button type="submit" variant="primary">Create promo code</x-ds.button></div>
                </form>
                <p class="sd-quiet">When a code does not work, a buyer is told only that it is not valid, so nobody can guess codes.</p>
            </aside>
        </div>
    </div>
</x-seller-layout>
