<?php

namespace App\Enums;

/**
 * The one lifecycle shared by orders and order lines. orders.status and
 * order_items.status used to be free strings; the only values anything
 * ever wrote were these five, so this enum names them and the casts on
 * Order/OrderItem refuse anything else.
 *
 * An order's own status is derived from its lines
 * (see App\Services\OrderLifecycle::refreshOrderStatus()), never set by
 * a seller directly.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Position on the forward-only fulfilment ladder. Cancelled sits
     * outside it: it is reachable only from the statuses in
     * cancellable().
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Processing => 1,
            self::Shipped => 2,
            self::Delivered => 3,
            self::Cancelled => 4,
        };
    }

    /**
     * A line can be cancelled (by its seller or by the buyer) only before
     * it has shipped.
     */
    public function isCancellable(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    /**
     * Lines the seller still has to pack, post or hand over -- the only
     * lines for which the buyer's shipping details are shown to a seller.
     */
    public function needsShipping(): bool
    {
        return in_array($this, [self::Pending, self::Processing, self::Shipped], true);
    }

    public function isFinal(): bool
    {
        return $this === self::Delivered || $this === self::Cancelled;
    }

    /**
     * Statuses a seller may move a line to from this one: forward only,
     * and cancelled only while it is still cancellable.
     *
     * @return array<int, self>
     */
    public function sellerTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Shipped, self::Delivered, self::Cancelled],
            self::Processing => [self::Shipped, self::Delivered, self::Cancelled],
            self::Shipped => [self::Delivered],
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

        return match (true) {
            $live->isEmpty() => self::Cancelled,
            $live->every(fn (self $s) => $s === self::Delivered) => self::Delivered,
            $live->every(fn (self $s) => $s === self::Shipped || $s === self::Delivered) => self::Shipped,
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
        return ucfirst($this->value);
    }
}
