<?php

namespace App\Listeners;

use App\Services\Cart\CartManager;
use App\Services\Guests\GuestOrders;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After someone signs in: fold the cart they built as a guest into their account cart, and attach guest orders placed
 * with their verified e-mail address. Neither may ever block a sign-in.
 */
class OnSignIn
{
    public function __construct(private readonly CartManager $cart, private readonly GuestOrders $guestOrders) {}

    public function handle(Login $event): void
    {
        try {
            $this->cart->mergeGuestInto($event->user);
            $this->guestOrders->attachTo($event->user);
        } catch (Throwable $e) {
            Log::warning('Guest cart or orders could not be carried over at sign-in.', ['user_id' => $event->user->getAuthIdentifier(), 'error' => class_basename($e)]);
        }
    }
}
