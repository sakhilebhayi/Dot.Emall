<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartProblem;

/**
 * "Buy again": puts the lines of an earlier order back in the cart at TODAY's price and stock, through the cart service. A line that can
 * no longer be bought is left out and named with the reason; a line with less stock than before is added at the stock there is, and
 * said so. Cancelled lines are not repeated. Nothing is bought; the buyer still checks out.
 */
class Reorder
{
    public function __construct(private readonly CartManager $cart) {}

    /** @return array{added: list<string>, partial: list<string>, skipped: array<string, string>} */
    public function into(Order $order, ?User $user): array
    {
        $result = ['added' => [], 'partial' => [], 'skipped' => []];

        foreach ($order->items()->where('status', '!=', 'cancelled')->orderBy('id')->get() as $line) {
            $name = $line->displayName();
            $variant = $line->variant_id ? ProductVariant::query()->with('product.store')->find($line->variant_id) : null;

            if ($variant === null) {
                $result['skipped'][$name] = 'It is no longer sold.';

                continue;
            }

            try {
                $this->cart->add($user, $variant, (int) $line->quantity);
                $result['added'][] = $name;
            } catch (CartProblem $e) {
                $stock = (int) $variant->stock;

                if ($stock > 0 && CartManager::isBuyable($variant, $variant->product) && $stock < $line->quantity) {
                    try {
                        $this->cart->add($user, $variant, $stock);
                        $result['partial'][] = "{$name}: only {$stock} of {$line->quantity} in stock";

                        continue;
                    } catch (CartProblem $again) {
                        $e = $again;
                    }
                }

                $result['skipped'][$name] = rtrim($e->getMessage(), '.').'.';
            }
        }

        return $result;
    }

    /** One sentence for the flash message. @param array{added: list<string>, partial: list<string>, skipped: array<string, string>} $r */
    public static function sentence(array $r): string
    {
        $n = count($r['added']) + count($r['partial']);
        $parts = [];

        if ($n > 0) {
            $parts[] = "Added {$n} ".($n === 1 ? 'item' : 'items').' to your cart at today\'s prices.';
        }

        foreach ($r['partial'] as $p) {
            $parts[] = $p.'.';
        }

        foreach ($r['skipped'] as $name => $why) {
            $parts[] = "Left out \"{$name}\": {$why}";
        }

        return $parts === [] ? 'Nothing from that order could be added.' : implode(' ', $parts);
    }
}
