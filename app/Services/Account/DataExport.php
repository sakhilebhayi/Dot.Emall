<?php

namespace App\Services\Account;

use App\Models\Address;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Models\ProductAlert;
use App\Models\ReturnRequest;
use App\Models\SavedSearch;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;

/**
 * "Download my data" (POPIA): what Dot.Emall holds about one person, as plain JSON they can keep. Only that person's own rows:
 * profile basics, addresses, orders with lines and delivery details, returns, wishlist, saved searches, alerts and notification choices.
 * No password, token or other person's data. Product and store names are included so the file reads on its own.
 */
class DataExport
{
    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'about' => 'Everything Dot.Emall holds about you in your own account. Closing the account removes your profile, addresses, wishlist, saved searches and alerts; your orders and payment records stay under the name \'Former customer\'. Keep this file if you need a copy.',
            'profile' => ['name' => $user->name, 'email' => $user->email, 'created_at' => optional($user->created_at)->toIso8601String(), 'signed_in_with_infodot_at' => optional($user->infodot_signed_in_at)->toIso8601String()],
            'addresses' => Address::query()->where('user_id', $user->id)->get()->map(fn (Address $a) => $a->snapshot() + ['label' => $a->label, 'default_shipping' => $a->is_default_shipping, 'default_billing' => $a->is_default_billing])->all(),
            'orders' => Order::query()->where('user_id', $user->id)->with(['items', 'groups', 'refunds'])->orderBy('id')->get()->map(fn (Order $o) => [
                'number' => $o->reference(), 'placed_at' => $o->created_at->toIso8601String(), 'status' => $o->status->value, 'payment_status' => $o->payment_status->value,
                'currency' => $o->currencyCode(), 'subtotal' => $o->subtotal, 'discount' => $o->discount_amount, 'delivery' => $o->delivery_total, 'vat_included' => $o->vat_total, 'total' => $o->total,
                'promo_code' => $o->promo_code, 'delivered_to' => $o->shipping_snapshot ?? ['name' => $o->shipping_name, 'address' => $o->shipping_address, 'phone' => $o->shipping_phone],
                'parcels' => $o->groups->map(fn ($g) => ['seller' => $g->store_name, 'delivery_method' => $g->method_name, 'delivery' => $g->delivery_cents / 100])->all(),
                'items' => $o->items->map(fn ($i) => ['name' => $i->displayName(), 'seller' => $i->displayStoreName(), 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'total' => $i->total, 'status' => $i->status->value])->all(),
                'refunds' => $o->refunds->map(fn ($r) => ['amount' => $r->amount_cents / 100, 'status' => $r->status->value, 'kind' => $r->kind])->all(),
            ])->all(),
            'returns' => ReturnRequest::query()->where('requester_id', $user->id)->with('item')->orderBy('id')->get()->map(fn (ReturnRequest $r) => [
                'item' => $r->item?->displayName(), 'reason' => $r->reason_category->value, 'note' => $r->note, 'status' => $r->status->value, 'requested_at' => $r->created_at->toIso8601String(),
                'seller_response' => $r->seller_response, 'photos' => count($r->photo_paths ?? []),
            ])->all(),
            'wishlist' => WishlistItem::query()->whereIn('wishlist_id', Wishlist::query()->where('user_id', $user->id)->select('id'))->with('product:id,name')->get()->map(fn ($w) => ['product' => $w->product?->name, 'note' => $w->note, 'saved_at' => optional($w->created_at)->toIso8601String()])->all(),
            'saved_searches' => SavedSearch::query()->where('user_id', $user->id)->get(['name', 'query', 'filters', 'notify_frequency'])->toArray(),
            'alerts' => ProductAlert::query()->where('user_id', $user->id)->with('product:id,name')->get()->map(fn ($a) => ['product' => $a->product?->name, 'kind' => $a->kind, 'active' => (bool) $a->active])->all(),
            'notification_choices' => NotificationPreference::query()->where('user_id', $user->id)->get(['category', 'mail', 'in_app'])->toArray(),
        ];
    }
}
