<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Catalogue\Inventory;
use App\Services\Ledger\LedgerService;
use App\Services\Notifications\OrderNotifier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every change to an order line's status goes through here: the order state machine (D-5).
 *
 *   pending -> processing -> shipped -> delivered            seller marks shipped; seller or buyer marks delivered
 *   pending/processing -> ready_for_collection -> collected  collection orders (seller; the buyer may confirm collection)
 *   delivered/collected -> refunded                          only the system, when a return has been refunded
 *   pending/processing/ready_for_collection -> cancelled     seller or buyer, before the goods leave
 *
 * Nothing skips: there is no pending-to-delivered, a line cannot ship from an unpaid order, a collection parcel cannot be
 * "shipped" and a delivery parcel cannot be "ready for collection". Each move records a timestamp on the line, a row in the
 * status history (who, from, to, note), keeps the seller group (parcel) and the order's own derived status current, and
 * - when the whole parcel has been delivered - starts the ledger release clock.
 *
 * Cancelling is the interesting one. In a single transaction, holding a row lock on the order so two cancels (a seller and
 * a buyer racing, or a double click) serialise:
 *   1. the line becomes cancelled -- a no-op if it already is, so a replay can never restock or release a promo twice;
 *   2. its quantity goes back on the variant's stock (archived products included) with a stock movement;
 *   3. the order is re-priced without the line (App\Services\OrderPricing);
 *   4. the seller ledger is brought back in line (App\Services\Ledger\LedgerService);
 *   5. if no non-cancelled line of the promo's store remains, the promo code's used_count is given back exactly once;
 *   6. the order's own status is re-derived -- 'cancelled' when every line is;
 *   7. if the order was PAID, what the buyer is now owed back is recorded (App\Services\RefundService::reconcile) and,
 *      after the commit, a refund is requested from Paystack. A Paystack failure never undoes the cancellation: the
 *      debt stays on file as "refund required".
 */
class OrderLifecycle
{
    public function __construct(
        private readonly OrderPricing $pricing,
        private readonly RefundService $refunds,
        private readonly Inventory $inventory,
        private readonly LedgerService $ledger,
        private readonly OrderNotifier $notifier,
    ) {}

    /**
     * @throws InvalidArgumentException if the line has shipped or is delivered
     */
    public function cancelItem(OrderItem $item, ?User $actor = null, ?string $note = null, ?string $role = null): OrderItem
    {
        $changed = false;

        $line = DB::transaction(function () use ($item, $actor, $note, $role, &$changed) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $line = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($line->status === OrderStatus::Cancelled) {
                return $line;
            }

            if (! $line->status->isCancellable()) {
                throw new InvalidArgumentException('A line that has shipped or been delivered can no longer be cancelled.');
            }

            $this->cancelLine($order, $line, 'cancellation', $actor, $role ?? $this->roleOf($order, $line, $actor), $note);
            $this->refunds->reconcile($order);
            $changed = true;

            return $line;
        });

        if ($changed) {
            // After the commit (and never inside a caller's transaction): this
            // talks to Paystack and records, rather than throws, any failure.
            DB::afterCommit(fn () => $this->refunds->submitPending($item->order_id));
        }

        return $line;
    }

    /**
     * Gives up an order that required payment and was never paid: every
     * live line is cancelled through cancelLine() (restock, re-price, promo
     * use back), exactly as if the buyer had cancelled them. Re-checks under
     * the order lock, so a payment that lands first wins and nothing is
     * cancelled.
     *
     * @return bool whether anything was cancelled
     */
    public function cancelUnpaidOrder(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->requires_payment
                || ! $locked->payment_status->isOpen()
                || $locked->status === OrderStatus::Cancelled) {
                return false;
            }

            foreach ($locked->items()->orderBy('id')->lockForUpdate()->get() as $line) {
                if ($line->status === OrderStatus::Cancelled || ! $line->status->isCancellable()) {
                    continue;
                }

                $this->cancelLine($locked, $line, 'expiry', null, 'system', 'Not paid in time');
            }

            // Anything still open at Paystack is moot now. If the buyer pays
            // it anyway the money lands in "needs review", not in this order.
            $locked->payments()
                ->where('status', PaymentAttemptStatus::Pending->value)
                ->update(['status' => PaymentAttemptStatus::Abandoned->value]);

            $this->settleCancelledPaymentStatus($locked);

            return true;
        });
    }

    /**
     * The cancellation itself. The caller holds the order row lock and has
     * checked the line is live and cancellable.
     */
    private function cancelLine(Order $order, OrderItem $line, string $stockReason, ?User $actor, string $role, ?string $note): void
    {
        $from = $line->status;
        $line->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
        $this->history($order, $line, $from, OrderStatus::Cancelled, $actor, $role, $note);

        $variantId = $line->variant_id ?? ($line->product_id !== null
            ? ProductVariant::query()->where('product_id', $line->product_id)->orderByDesc('is_default')->orderBy('id')->value('id')
            : null);

        if ($variantId !== null) {
            $this->inventory->give((int) $variantId, $line->quantity, $stockReason, $actor?->id, $order->id, $line->id, $note);
        }

        $this->pricing->apply($order);
        $this->ledger->syncGroup($line->order_group_id, "Line cancelled: {$line->displayName()}", $line->id);
        $this->releasePromoIfUnused($order);
        $this->refreshOrderStatus($order);
        $this->syncGroup($line->order_group_id);

        if ($order->fresh()->status === OrderStatus::Cancelled) {
            $this->settleCancelledPaymentStatus($order);
        }
    }

    /**
     * Moves a line forward on the fulfilment ladder as its seller (the default), as its buyer (role 'buyer' or 'guest':
     * only confirming they have the goods) or as an operator. Cancelling is routed to cancelItem() so it always restocks
     * and re-prices.
     *
     * @throws InvalidArgumentException when the move is not allowed
     */
    public function advanceItem(OrderItem $item, OrderStatus $to, ?User $actor = null, ?string $note = null, string $role = 'seller', bool $notify = true): OrderItem
    {
        if ($to === OrderStatus::Cancelled) {
            return $this->cancelItem($item, $actor, $note, $role);
        }

        $line = DB::transaction(function () use ($item, $to, $actor, $note, $role) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $line = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $order->isFulfillable()) {
                throw new InvalidArgumentException('This order is awaiting payment, so it cannot be fulfilled yet. Do not ship it until it is paid.');
            }

            if (! in_array($to, $this->allowedFrom($line->status, $role), true)) {
                throw new InvalidArgumentException('That status change is not allowed.');
            }

            $group = $line->order_group_id !== null ? OrderGroup::query()->find($line->order_group_id) : null;

            if ($group !== null && $to === OrderStatus::Shipped && $group->isCollection()) {
                throw new InvalidArgumentException('This parcel is for collection, so mark it ready for collection instead of shipped.');
            }

            if ($group !== null && $to === OrderStatus::ReadyForCollection && ! $group->isCollection()) {
                throw new InvalidArgumentException('This parcel is for delivery, so it cannot be marked ready for collection.');
            }

            $from = $line->status;
            $line->update(['status' => $to, $this->timestampColumn($to) => now()]);
            $this->history($order, $line, $from, $to, $actor, $role, $note);
            $this->syncGroup($line->order_group_id);
            $this->refreshOrderStatus($order);

            return $line;
        });

        if ($notify) {
            $line->loadMissing('order');
            $this->afterCommit(fn () => $this->notifier->itemMoved($line, $to->value, $line->shipments()->latest('shipments.id')->first()));
        }

        return $line;
    }

    /**
     * The buyer (or a guest holding the order link) confirms they have the goods.
     */
    public function confirmReceipt(OrderItem $item, ?User $buyer = null): OrderItem
    {
        $order = Order::query()->findOrFail($item->order_id);

        if ($buyer !== null && $order->user_id !== $buyer->id) {
            throw new InvalidArgumentException('Only the buyer can confirm receipt.');
        }

        $to = $item->status === OrderStatus::ReadyForCollection ? OrderStatus::Collected : OrderStatus::Delivered;

        return $this->advanceItem($item, $to, $buyer, 'Confirmed by the buyer', $buyer !== null ? 'buyer' : 'guest');
    }

    /**
     * A returned line has been refunded: the system moves it delivered/collected -> refunded.
     */
    public function markRefunded(OrderItem $item, ?User $actor, string $note): OrderItem
    {
        return DB::transaction(function () use ($item, $actor, $note) {
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            $line = OrderItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($line->status === OrderStatus::Refunded) {
                return $line;
            }

            if (! in_array(OrderStatus::Refunded, $line->status->systemTransitions(), true)) {
                throw new InvalidArgumentException('Only a delivered or collected line can be refunded.');
            }

            $from = $line->status;
            $line->update(['status' => OrderStatus::Refunded, 'refunded_at' => now()]);
            $this->history($order, $line, $from, OrderStatus::Refunded, $actor, $actor === null ? 'system' : $this->roleOf($order, $line, $actor), $note);
            $this->ledger->syncGroup($line->order_group_id, "Line refunded after delivery: {$line->displayName()}", $line->id);
            $this->syncGroup($line->order_group_id);
            $this->refreshOrderStatus($order);

            return $line;
        });
    }

    /**
     * Statuses a line may move to from $from for this kind of actor.
     *
     * @return array<int, OrderStatus>
     */
    public function allowedFrom(OrderStatus $from, string $role): array
    {
        return match ($role) {
            'buyer', 'guest' => $from->buyerTransitions(),
            'system' => array_merge($from->sellerTransitions(), $from->systemTransitions()),
            'operator' => array_merge($from->sellerTransitions(), $from->buyerTransitions()),
            default => $from->sellerTransitions(),
        };
    }

    /**
     * An order's status is derived from its lines, never set directly:
     * cancelled lines are ignored, and an order with nothing but cancelled
     * lines is itself cancelled.
     */
    public function refreshOrderStatus(Order $order): void
    {
        $statuses = $order->items()->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $status = OrderStatus::derive($statuses);

        $values = ['status' => $status];

        if ($status->isCompleted() && $order->delivered_at === null) {
            $values['delivered_at'] = now();
        }

        $order->update($values);
    }

    /**
     * The parcel's own status, its shipped/delivered timestamps, and the ledger release clock.
     */
    private function syncGroup(?int $groupId): void
    {
        if ($groupId === null) {
            return;
        }

        $group = OrderGroup::query()->find($groupId);

        if ($group === null) {
            return;
        }

        $statuses = OrderItem::query()->where('order_group_id', $groupId)->pluck('status');
        $status = OrderStatus::derive($statuses);
        $values = ['status' => $status->value];

        $live = $statuses->reject(fn ($s) => $s === OrderStatus::Cancelled);

        if ($group->shipped_at === null && $live->contains(fn ($s) => in_array($s, [OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Collected, OrderStatus::ReadyForCollection], true))) {
            $values['shipped_at'] = now();
        }

        $completed = $live->isNotEmpty() && $live->every(fn ($s) => in_array($s, [OrderStatus::Delivered, OrderStatus::Collected, OrderStatus::Refunded], true))
            && $live->contains(fn ($s) => $s->isCompleted());

        if ($completed && $group->delivered_at === null) {
            $values['delivered_at'] = now();
        }

        $group->forceFill($values)->save();

        if ($completed) {
            $this->ledger->scheduleRelease($group->refresh(), $group->delivered_at);
        }
    }

    private function history(Order $order, OrderItem $line, ?OrderStatus $from, OrderStatus $to, ?User $actor, string $role, ?string $note): void
    {
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'order_item_id' => $line->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            'actor_role' => $role,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }

    private function roleOf(Order $order, OrderItem $line, ?User $actor): string
    {
        return match (true) {
            $actor === null => 'system',
            $actor->id === $order->user_id => 'buyer',
            $actor->id === $line->seller_id => 'seller',
            (bool) $actor->is_platform_operator => 'operator',
            default => 'seller',
        };
    }

    private function timestampColumn(OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Processing => 'processing_at',
            OrderStatus::Shipped => 'shipped_at',
            OrderStatus::ReadyForCollection => 'ready_at',
            OrderStatus::Delivered => 'delivered_at',
            OrderStatus::Collected => 'collected_at',
            OrderStatus::Cancelled => 'cancelled_at',
            OrderStatus::Refunded => 'refunded_at',
            default => 'updated_at',
        };
    }

    /**
     * An order that ended cancelled with nothing paid must not read "payment pending" for ever (it was an audit finding):
     * no attempt is open any more, so it is simply unpaid.
     */
    private function settleCancelledPaymentStatus(Order $order): void
    {
        $order->refresh();

        if ($order->payment_status === PaymentStatus::Pending) {
            $order->payments()->where('status', PaymentAttemptStatus::Pending->value)->update(['status' => PaymentAttemptStatus::Abandoned->value]);
            $order->forceFill(['payment_status' => PaymentStatus::Unpaid])->save();
        }
    }

    private function afterCommit(callable $callback): void
    {
        DB::afterCommit($callback);
    }

    private function releasePromoIfUnused(Order $order): void
    {
        if ($order->promo_code_id === null || $order->promo_released_at !== null) {
            return;
        }

        if ($this->pricing->promoStillApplies($order)) {
            return;
        }

        PromoCode::query()
            ->where('id', $order->promo_code_id)
            ->where('used_count', '>', 0)
            ->decrement('used_count');

        $order->update(['promo_released_at' => now()]);
    }
}
