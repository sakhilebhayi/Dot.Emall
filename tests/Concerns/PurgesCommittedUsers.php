<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * The PostgreSQL concurrency tests commit real rows (child processes cannot see an open test transaction) and must remove them
 * again. Since D-98 the keys from orders, stores, ledger entries and returns to users RESTRICT, so deleting the users no longer
 * takes their sales with them: the records go first, in dependency order, exactly as a hand-run clean-up would have to.
 */
trait PurgesCommittedUsers
{
    /** @param array<int, int> $userIds */
    protected function purgeCommittedUsers(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $storeIds = DB::table('stores')->whereIn('owner_id', $userIds)->pluck('id');
        $orderIds = DB::table('orders')->whereIn('user_id', $userIds)
            ->orWhereIn('id', DB::table('order_items')->whereIn('store_id', $storeIds)->select('order_id'))->pluck('id');

        DB::table('notifications')->whereIn('notifiable_id', $userIds)->delete();
        DB::table('commerce_events')->whereIn('order_id', $orderIds)->delete();
        DB::table('payment_events')->whereIn('order_id', $orderIds)->delete();
        DB::table('refunds')->whereIn('order_id', $orderIds)->delete();
        DB::table('payments')->whereIn('order_id', $orderIds)->delete();
        DB::table('return_requests')->where(fn ($q) => $q->whereIn('order_id', $orderIds)->orWhereIn('requester_id', $userIds)->orWhereIn('seller_id', $userIds))->delete();
        DB::table('ledger_entries')->where(fn ($q) => $q->whereIn('order_id', $orderIds)->orWhereIn('seller_id', $userIds))->delete();
        DB::table('order_groups')->where(fn ($q) => $q->whereIn('order_id', $orderIds)->orWhereIn('seller_id', $userIds))->delete();
        DB::table('order_items')->where(fn ($q) => $q->whereIn('order_id', $orderIds)->orWhereIn('seller_id', $userIds))->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        DB::table('reviews')->whereIn('user_id', $userIds)->delete();
        DB::table('products')->whereIn('store_id', $storeIds)->delete();
        DB::table('promo_codes')->whereIn('store_id', $storeIds)->delete();
        DB::table('stores')->whereIn('id', $storeIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
    }
}
