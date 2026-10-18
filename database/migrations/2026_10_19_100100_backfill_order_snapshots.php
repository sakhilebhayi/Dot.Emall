<?php

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Fills the snapshot columns for order lines that predate them, folds
     * the old free-string statuses into the OrderStatus enum's five
     * values, and repairs the totals the old cancel path got wrong.
     *
     * What this can and cannot know about legacy data:
     *  - name / store / seller are copied from the live product and store.
     *    Every surviving line still has its product (the old FK cascaded,
     *    so lines whose product was deleted are already gone).
     *  - a legacy promo is matched by code; if the code row has since been
     *    deleted its discount stays as charged and is spread over all lines.
     *  - orders with no cancelled line keep the discount they were charged,
     *    to the cent. Only orders that contain a cancelled line are
     *    re-totalled (cancelled lines used to count), using the promo's
     *    current rules.
     *  - stock and promo use_count are NOT retroactively returned for
     *    legacy cancelled lines: whether an operator already corrected
     *    them by hand is unknowable from the data.
     */
    public function up(): void
    {
        $this->normaliseStatuses();
        $this->backfillLineSnapshots();
        $this->backfillOrderPricing();
    }

    public function down(): void
    {
        // Data repair only; nothing to undo. The columns are dropped by the
        // previous migration's down().
    }

    private function normaliseStatuses(): void
    {
        $valid = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

        foreach (['orders', 'order_items'] as $table) {
            DB::table($table)->where('status', 'confirmed')->update(['status' => 'processing']);
            DB::table($table)->where('status', 'refunded')->update(['status' => 'cancelled']);
            DB::table($table)->whereNotIn('status', $valid)->update(['status' => 'pending']);
        }
    }

    private function backfillLineSnapshots(): void
    {
        DB::table('order_items')
            ->whereNull('product_name')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->chunkById(500, function ($lines) {
                $products = DB::table('products')
                    ->join('stores', 'stores.id', '=', 'products.store_id')
                    ->whereIn('products.id', $lines->pluck('product_id')->unique())
                    ->get(['products.id as product_id', 'products.name as product_name', 'products.store_id', 'stores.name as store_name', 'stores.owner_id as seller_id'])
                    ->keyBy('product_id');

                foreach ($lines as $line) {
                    $p = $products->get($line->product_id);

                    if ($p === null) {
                        continue;
                    }

                    DB::table('order_items')->where('id', $line->id)->update([
                        'product_name' => $p->product_name,
                        'store_id' => $p->store_id,
                        'store_name' => $p->store_name,
                        'seller_id' => $p->seller_id,
                    ]);
                }
            });
    }

    private function backfillOrderPricing(): void
    {
        // Orders that need pricing work: anything with a discount, anything
        // with a cancelled line.
        $orderIds = DB::table('orders')
            ->where(fn ($q) => $q->where('discount_amount', '>', 0)
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where('order_items.status', 'cancelled')))
            ->pluck('id');

        foreach ($orderIds->chunk(200) as $chunk) {
            foreach ($chunk as $orderId) {
                $this->repriceOrder((int) $orderId);
            }
        }

        // An order whose every line is cancelled is itself cancelled (the
        // old derivation reported such an order as "shipped").
        $allCancelled = DB::table('orders')
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('order_items')->whereColumn('order_items.order_id', 'orders.id'))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('order_items')->whereColumn('order_items.order_id', 'orders.id')->where('order_items.status', '!=', 'cancelled'))
            ->pluck('id');

        foreach ($allCancelled->chunk(500) as $chunk) {
            DB::table('orders')->whereIn('id', $chunk)->update(['status' => 'cancelled']);
        }
    }

    private function repriceOrder(int $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        $lines = DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get();

        $promo = null;
        if ($order->promo_code !== null && $order->promo_code !== '') {
            $promo = DB::table('promo_codes')->whereRaw('UPPER(code) = ?', [Str::upper($order->promo_code)])->first();
        }

        $hasCancelled = $lines->contains(fn ($l) => $l->status === 'cancelled');
        $active = $lines->reject(fn ($l) => $l->status === 'cancelled');

        $subtotal = $active->sum(fn ($l) => Money::toCents($l->total));
        $originalDiscount = Money::toCents($order->discount_amount);

        $eligible = $active;
        if ($promo !== null) {
            $eligible = $active->filter(fn ($l) => (int) $l->store_id === (int) $promo->store_id);
        }

        $eligibleCents = $eligible->sum(fn ($l) => Money::toCents($l->total));

        if ($hasCancelled) {
            $discount = $promo !== null
                ? Money::discountCents($promo->discount_type, $promo->discount_value, $eligibleCents)
                : min($originalDiscount, $subtotal);
        } else {
            $discount = $originalDiscount;
        }

        $weights = $eligible->mapWithKeys(fn ($l) => [$l->id => Money::toCents($l->total)])->all();

        // Promo applied but nothing eligible left (or the code never matched
        // a store): spread over all active lines instead of dropping it.
        if ($discount > 0 && array_sum($weights) === 0) {
            $weights = $active->mapWithKeys(fn ($l) => [$l->id => Money::toCents($l->total)])->all();
        }

        $shares = Money::allocate($discount, $weights);

        foreach ($lines as $line) {
            DB::table('order_items')->where('id', $line->id)->update([
                'discount_amount' => Money::fromCents($shares[$line->id] ?? 0),
            ]);
        }

        $update = [
            'subtotal' => Money::fromCents($subtotal),
            'discount_amount' => Money::fromCents($discount),
            'total' => Money::fromCents(max(0, $subtotal - $discount)),
        ];

        if ($promo !== null) {
            $update += [
                'promo_code_id' => $promo->id,
                'promo_store_id' => $promo->store_id,
                'promo_discount_type' => $promo->discount_type,
                'promo_discount_value' => $promo->discount_value,
            ];
        }

        DB::table('orders')->where('id', $orderId)->update($update);
    }
};
