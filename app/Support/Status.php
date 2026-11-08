<?php

namespace App\Support;

/**
 * The status vocabulary (DESIGN.md, "Status vocabulary"). Every state has a WORD, an icon SHAPE and a TONE, so no state is ever carried by
 * colour alone and no two states share a silhouette. <x-ds.status kind="order|product" state="..."> reads this table.
 *
 * Tones: pos (good), cau (needs attention), crit (failed or stopped), info (in progress), neutral (inactive or closed).
 */
final class Status
{
    /** @var array<string, array{0: string, 1: string, 2: string}> word, icon, tone */
    public const ORDER = [
        'awaiting_payment' => ['Awaiting payment', 'clock', 'cau'],
        'paid' => ['Paid', 'card', 'pos'],
        'packing' => ['Packing', 'box', 'info'],
        'shipped' => ['Shipped', 'truck', 'info'],
        'ready_for_collection' => ['Ready for collection', 'store', 'info'],
        'delivered' => ['Delivered', 'package-check', 'pos'],
        'collected' => ['Collected', 'check', 'pos'],
        'cancelled' => ['Cancelled', 'x-circle', 'neutral'],
        'refunded' => ['Refunded', 'undo', 'neutral'],
        'return_requested' => ['Return requested', 'return', 'cau'],
        'return_approved' => ['Return approved', 'check-circle', 'pos'],
        'return_declined' => ['Return declined', 'ban', 'crit'],
        'payment_failed' => ['Payment failed', 'alert-triangle', 'crit'],
        'pending' => ['Pending', 'hourglass', 'neutral'],
    ];

    /** @var array<string, array{0: string, 1: string, 2: string}> */
    public const PRODUCT = [
        'in_stock' => ['In stock', 'in-stock', 'pos'],
        'low_stock' => ['Low stock', 'alert-triangle', 'cau'],
        'out_of_stock' => ['Out of stock', 'minus-circle', 'neutral'],
        'unlisted' => ['Unlisted', 'eye-off', 'neutral'],
        'pending_review' => ['Pending review', 'hourglass', 'cau'],
        'rejected' => ['Rejected', 'x-octagon', 'crit'],
    ];

    /** Words the first version of the shop used, mapped to the vocabulary above. */
    private const ALIASES = [
        'awaiting payment' => 'awaiting_payment',
        'payment failed' => 'payment_failed',
        'processing' => 'packing',
        'pending_approval' => 'pending_review',
        'approved' => 'in_stock',
        'inactive' => 'unlisted',
        'out of stock' => 'out_of_stock',
    ];

    /** A remaining quantity at or under this is shown to shoppers as a real number. */
    public const LOW_STOCK_AT = 5;

    /**
     * @return array{state: string, word: string, icon: string, tone: string}
     */
    public static function resolve(string $kind, string $state, ?int $quantity = null): array
    {
        $table = $kind === 'product' ? self::PRODUCT : self::ORDER;
        $key = strtolower(trim($state));
        $key = self::ALIASES[$key] ?? str_replace([' ', '-'], '_', $key);

        if (! isset($table[$key])) {
            return ['state' => $key, 'word' => ucfirst(str_replace('_', ' ', $key)), 'icon' => 'info', 'tone' => 'neutral'];
        }

        [$word, $icon, $tone] = $table[$key];
        if ($kind === 'product' && $key === 'low_stock' && $quantity !== null && $quantity > 0) {
            $word = 'Only '.$quantity.' left';
        }

        return ['state' => $key, 'word' => $word, 'icon' => $icon, 'tone' => $tone];
    }

    /** in_stock, low_stock (real quantity at or under LOW_STOCK_AT) or out_of_stock. */
    public static function stockState(?int $quantity): string
    {
        if ($quantity === null) {
            return 'in_stock';
        }

        return $quantity <= 0 ? 'out_of_stock' : ($quantity <= self::LOW_STOCK_AT ? 'low_stock' : 'in_stock');
    }
}
