<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once, inside the checkout transaction, the moment a buyer's cart
 * becomes a real Order (App\Http\Controllers\CartController::checkout()).
 *
 * This is the first domain event Dot.Emall dispatches at all — wiki.md §5
 * previously listed "Order placed" as a purely conceptual trigger with no
 * actual event dispatch behind it. It is scoped to this one, real trigger
 * point rather than the full roadmap list in wiki.md §7 (listing published,
 * listing unsold expiry, review submitted, etc.), because checkout is the
 * only one of those flows that currently exists in the codebase — the
 * others (seller product CRUD, expiry jobs) have no controller to dispatch
 * from yet and would mean inventing an event with no real trigger, which
 * this platform's engineering loop treats as fabrication.
 *
 * No listener is registered yet — this event exists so a future Knowledge
 * Pack publisher (wiki.md §6, not yet built) has a real signal to subscribe
 * to instead of having to be wired directly into the controller later.
 */
class OrderPlaced
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order)
    {
    }
}
