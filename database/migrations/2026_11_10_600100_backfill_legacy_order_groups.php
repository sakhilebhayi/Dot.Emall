<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Orders placed before shipping existed have lines with no parcel (order_group_id is null), so the seller desk could not list,
 * ship or earn on them. Gives each (order, store) of such lines a parcel with delivery "arranged" and nothing charged, which is
 * what the buyer was actually told, then points the lines at it. Idempotent: it only touches lines that still have no parcel,
 * and it never changes an amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('order_items')->whereNull('order_group_id')->whereNotNull('store_id')
            ->select('order_id', 'store_id')->distinct()->orderBy('order_id')->orderBy('store_id')
            ->get()
            ->each(function ($pair) {
                $lines = DB::table('order_items')->where('order_id', $pair->order_id)->where('store_id', $pair->store_id)->whereNull('order_group_id')->get(['seller_id', 'store_name', 'status']);

                $existing = DB::table('order_groups')->where('order_id', $pair->order_id)->where('store_id', $pair->store_id)->value('id');

                $groupId = $existing ?? DB::table('order_groups')->insertGetId([
                    'order_id' => $pair->order_id,
                    'store_id' => $pair->store_id,
                    'seller_id' => $lines->first()->seller_id,
                    'store_name' => $lines->first()->store_name ?? 'Store',
                    'method_type' => 'arranged',
                    'delivery_cents' => 0,
                    'vat_cents' => 0,
                    'status' => OrderStatus::derive($lines->map(fn ($l) => OrderStatus::tryFrom((string) $l->status) ?? OrderStatus::Pending))->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('order_items')->where('order_id', $pair->order_id)->where('store_id', $pair->store_id)->whereNull('order_group_id')->update(['order_group_id' => $groupId]);
            });
    }

    public function down(): void
    {
        // Parcels made here carry no information that was not already on the lines; they are left in place.
    }
};
