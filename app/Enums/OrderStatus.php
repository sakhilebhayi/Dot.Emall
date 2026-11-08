<?php

namespace App\Enums;

/**
 * The one lifecycle shared by orders and order lines (D-5).
 *
 *   pending -> processing ("packing") -> shipped -> delivered
 *   pending/processing -> ready_for_collection -> collected       (collection method)
 *   delivered/collected -> refunded                                (a return that was refunded)
 *   pending/processing/ready_for_collection -> cancelled
 *
 * "Paid" is not a status here: money is Order::payment_status. A line can
 * never jump from pending to delivered; a seller must mark it shipped first
 * (or ready for collection), and the buyer may confirm receipt.
 *
 * An order's own status is derived from its lines
 * (App\Services\OrderLifecycle::refreshOrderStatus()), never set directly.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case ReadyForCollection = 'ready_for_collection';
    case Delivered = 'delivered';
    case Collected = 'collected';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * Position on the forward-only fulfilment ladder. Cancelled and refunded
     * sit outside it.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Processing => 1,
            self::ReadyForCollection => 2,
            self::Shipped => 2,
            self::Delivered => 3,
            self::Collected => 3,
            self::Cancelled => 4,
            self::Refunded => 5,
        };
    }

    /**
     * A line can be cancelled (by its seller or by the buyer) only before it has left the seller.
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::ReadyForCollection], true);
    }

    /**
     * Lines the seller still has to pack, post or hand over -- the only
     * lines for which the buyer's shipping details are shown to a seller.
     */
    public function needsShipping(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::Shipped, self::ReadyForCollection], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Collected, self::Cancelled, self::Refunded], true);
    }

    /**
     * The goods reached the buyer (and, for Refunded, did once): the states in which a review is possible, the
     * return window runs and the seller's money counts.
     */
    public function isCompleted(): bool
    {
        return in_array($this, [self::Delivered, self::Collected], true);
    }

    /**
     * @return array<int, string> the values of the completed states, for SQL
     */
    public static function completedValues(): array
    {
        return [self::Delivered->value, self::Collected->value];
    }

    /**
     * Statuses a seller may move a line to from this one.
     *
     * @return array<int, self>
     */
    public function sellerTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Shipped, self::ReadyForCollection, self::Cancelled],
            self::Processing => [self::Shipped, self::ReadyForCollection, self::Cancelled],
            self::ReadyForCollection => [self::Collected, self::Cancelled],
            self::Shipped => [self::Delivered],
            default => [],
        };
    }

    /**
     * What a buyer (or a guest holding the order link) may do: confirm they have the goods.
     *
     * @return array<int, self>
     */
    public function buyerTransitions(): array
    {
        return match ($this) {
            self::Shipped => [self::Delivered],
            self::ReadyForCollection => [self::Collected],
            default => [],
        };
    }

    /**
     * Moves only the system makes, on a return that has been refunded.
     *
     * @return array<int, self>
     */
    public function systemTransitions(): array
    {
        return match ($this) {
            self::Delivered, self::Collected => [self::Refunded],
            default => [],
        };
    }

    /**
     * Derives the status of a group of lines (a whole order, or one
     * seller's slice of it): cancelled lines are ignored, a group with
     * nothing but cancelled lines is cancelled, and otherwise the group is
     * only as far along as its slowest live line.
     *
     * @param  iterable<int, self>  $statuses
     */
    public static function derive(iterable $statuses): self
    {
        $statuses = collect($statuses);

        if ($statuses->isEmpty()) {
            return self::Pending;
        }

        $live = $statuses->reject(fn (self $s) => $s === self::Cancelled);

        $done = fn (self $s) => in_array($s, [self::Delivered, self::Collected, self::Refunded], true);

        return match (true) {
            $live->isEmpty() => self::Cancelled,
            $live->every(fn (self $s) => $s === self::Refunded) => self::Refunded,
            $live->every($done) => $live->contains(self::Delivered) || ! $live->contains(self::Collected) ? self::Delivered : self::Collected,
            $live->every(fn (self $s) => $s === self::Shipped || $done($s)) => self::Shipped,
            $live->every(fn (self $s) => in_array($s, [self::ReadyForCollection], true) || $done($s)) => self::ReadyForCollection,
            $live->contains(fn (self $s) => $s !== self::Pending) => self::Processing,
            default => self::Pending,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'Packing',
            self::ReadyForCollection => 'Ready for collection',
            default => ucfirst($this->value),
        };
    }
}
