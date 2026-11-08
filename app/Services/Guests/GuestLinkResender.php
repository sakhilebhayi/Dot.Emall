<?php

namespace App\Services\Guests;

use App\Models\Order;
use App\Notifications\CommerceNotification;
use Illuminate\Support\Facades\Notification;

/**
 * A guest who lost the order e-mail, or whose link expired, asks for it again (R-43 buyer QA, D-140).
 *
 * The answer to the person is always the same, whether or not that address has orders, so the form cannot be used to find out
 * who has shopped here. Links go only to the address the order was placed with. A link that is still valid is sent again as
 * it was (earlier e-mails keep working); one that has expired is rotated first, so a copy of the old link someone else might
 * hold stays dead. Orders whose link was deliberately revoked, and orders already moved into an account, get nothing.
 */
class GuestLinkResender
{
    /** The most recent orders mailed in one request: enough for a recent shopper, small enough not to be a mail cannon. */
    public const MAX_ORDERS = 3;

    public function __construct(private readonly GuestOrderAccess $access) {}

    /** Returns how many order links were sent (never shown to the person). */
    public function send(string $email): int
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 0;
        }

        $orders = Order::query()
            ->whereNull('user_id')
            ->whereRaw('LOWER(guest_email) = ?', [$email])
            ->orderByDesc('id')
            ->limit(self::MAX_ORDERS)
            ->get();

        $sent = 0;

        foreach ($orders as $order) {
            if ($order->guest_token_expires_at !== null && $order->guest_token_expires_at->isPast() && $order->guest_token_hash !== null) {
                $this->access->rotate($order);
                $order->refresh();
            }

            if ($this->access->url($order) === null) {
                continue;
            }

            Notification::route('mail', $order->guest_email)->notify(new CommerceNotification('order_link', $order->id));
            $sent++;
        }

        return $sent;
    }
}
