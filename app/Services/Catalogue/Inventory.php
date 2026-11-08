<?php

namespace App\Services\Catalogue;

use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Services\CheckoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every stock change goes through here, inside the caller's transaction.
 *
 * The guarantees are those checkout always had, moved to the variant:
 *  - take() is a WHERE-guarded decrement (`stock >= qty`), so stock can never go below zero even where row locks
 *    are no-ops (SQLite); callers lock the product and variant rows first (products in id order, then variants in
 *    id order) so concurrent checkouts cannot deadlock;
 *  - give() puts units back (a cancelled or expired order, a received return);
 *  - setStock() applies a seller's edit as the DELTA they made (typed minus the stock they were looking at) to
 *    today's stock, so a price-only edit can never resurrect units sold while the form was open;
 *  - each change bumps stock_version, refreshes the product's aggregate stock and writes a StockMovement
 *    (reason, actor, before, after).
 */
class Inventory
{
    public function __construct(private readonly VariantSync $sync) {}

    /**
     * @throws CheckoutException when fewer than $quantity are left
     */
    public function take(ProductVariant $variant, int $quantity, string $reason = 'sale', ?int $actorId = null, ?int $orderId = null, ?int $orderItemId = null, ?string $note = null): void
    {
        $updated = ProductVariant::query()
            ->whereKey($variant->id)
            ->where('stock', '>=', $quantity)
            ->update(['stock' => DB::raw('stock - '.(int) $quantity), 'stock_version' => DB::raw('stock_version + 1')]);

        if ($updated === 0) {
            throw new OutOfStock($variant, $quantity);
        }

        $after = (int) ProductVariant::query()->whereKey($variant->id)->value('stock');
        $this->recordMovement($variant, -$quantity, $after, $reason, $actorId, $orderId, $orderItemId, $note);
        $this->sync->refreshProduct($variant->product_id);
    }

    public function give(int $variantId, int $quantity, string $reason, ?int $actorId = null, ?int $orderId = null, ?int $orderItemId = null, ?string $note = null): void
    {
        $variant = ProductVariant::query()->find($variantId);

        if ($variant === null || $quantity <= 0) {
            return;
        }

        ProductVariant::query()->whereKey($variantId)->update(['stock' => DB::raw('stock + '.(int) $quantity), 'stock_version' => DB::raw('stock_version + 1')]);

        $after = (int) ProductVariant::query()->whereKey($variantId)->value('stock');
        $this->recordMovement($variant, $quantity, $after, $reason, $actorId, $orderId, $orderItemId, $note);
        $this->sync->refreshProduct($variant->product_id);
    }

    /**
     * A seller edited a stock number. $seen is the number the form showed (hidden `stock_was` field); without it
     * the typed number is taken as given. Returns the stock actually stored.
     */
    public function setStock(ProductVariant $variant, int $typed, ?int $seen, ?int $actorId, string $reason = 'seller_edit'): int
    {
        return DB::transaction(function () use ($variant, $typed, $seen, $actorId, $reason) {
            $current = ProductVariant::query()->lockForUpdate()->findOrFail($variant->id);
            $before = (int) $current->stock;

            $target = $seen === null ? $typed : max(0, $before + ($typed - $seen));

            if ($target === $before) {
                return $before;
            }

            $current->forceFill(['stock' => $target]);

            VariantSync::quietly(function () use ($current) {
                $current->save();
                $current->increment('stock_version');
            });

            $this->recordMovement($current, $target - $before, $target, $reason, $actorId, null, null, $seen === null ? null : "Seller typed {$typed} over {$seen}");
            $this->sync->refreshProduct($current->product_id);

            return $target;
        });
    }

    /**
     * The variants of a store whose stock is at or below THEIR PRODUCT's own low-stock threshold (the seller sets it), lowest first.
     *
     * @return Collection<int, ProductVariant>
     */
    public function lowStock(Store $store, int $limit = 100): Collection
    {
        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.store_id', $store->id)
            ->whereNull('products.deleted_at')
            ->where('product_variants.is_active', true)
            ->whereColumn('product_variants.stock', '<=', 'products.low_stock_threshold')
            ->orderBy('product_variants.stock')->orderBy('product_variants.id')
            ->limit($limit)
            ->select('product_variants.*')
            ->with('product:id,name,low_stock_threshold')
            ->get();
    }

    public function recordMovement(ProductVariant $variant, int $delta, int $after, string $reason, ?int $actorId, ?int $orderId, ?int $orderItemId, ?string $note = null): StockMovement
    {
        return StockMovement::create([
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'delta' => $delta,
            'stock_before' => max(0, $after - $delta),
            'stock_after' => $after,
            'reason' => $reason,
            'actor_id' => $actorId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'note' => $note,
        ]);
    }
}
