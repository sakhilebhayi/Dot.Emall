<?php

namespace App\Services\Guests;

use App\Models\Order;
use Illuminate\Support\Facades\URL;

/**
 * The magic link a guest follows to see their order (D-9).
 *
 * The token is an HMAC of (order id, guest e-mail, token version) under the app key: unguessable without the key, and
 * recomputable, so every later e-mail about the order (shipped, delivered...) can carry the same working link without
 * the plain token ever being stored. Only its SHA-256 is kept (orders.guest_token_hash); rotate() bumps the version
 * (old links stop working), revoke() clears the hash, and a link also dies at guest_token_expires_at or the moment the
 * order is attached to an account.
 *
 * A valid link opens ONE order, as its buyer. It grants nothing else: not the e-mail address's other orders, and no
 * account. Following it stores the grant in the session so the guest can pay, confirm receipt or ask for a return from
 * that browser without re-presenting the token. The grant is tied to the link's version: rotate() ends it, as revoke() and expiry do.
 */
class GuestOrderAccess
{
    public const SESSION_KEY = 'emall.guest_orders';

    /** Sets the first token on a new guest order and returns it. */
    public function issue(Order $order): string
    {
        $order->forceFill([
            'guest_token_version' => max(1, (int) $order->guest_token_version),
            'guest_token_expires_at' => now()->addDays((int) config('emall.guest.token_days', 180)),
        ]);

        $token = $this->compute($order);
        $order->forceFill(['guest_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /** The current plain token of a still-linkable guest order, or null (not a guest order, attached, revoked, expired). */
    public function tokenFor(Order $order): ?string
    {
        if (! $this->linkable($order)) {
            return null;
        }

        return $this->compute($order);
    }

    public function url(Order $order): ?string
    {
        $token = $this->tokenFor($order);

        return $token === null ? null : URL::route('guest.orders.access', ['order' => $order->id, 'token' => $token]);
    }

    public function verify(Order $order, string $token): bool
    {
        if (! $this->linkable($order) || $token === '' || strlen($token) > 128) {
            return false;
        }

        return hash_equals((string) $order->guest_token_hash, hash('sha256', $token))
            && hash_equals($this->compute($order), $token);
    }

    /** Invalidates every link already sent and returns the new token. */
    public function rotate(Order $order): string
    {
        $order->forceFill(['guest_token_version' => (int) $order->guest_token_version + 1]);

        return $this->issue($order);
    }

    public function revoke(Order $order): void
    {
        $order->forceFill(['guest_token_hash' => null])->save();
    }

    public function grantSession(Order $order): void
    {
        $granted = (array) session(self::SESSION_KEY, []);
        // The grant remembers which version of the link opened it, so rotating the link (which is what the person is told to do when a link
        // leaked) also ends the access a browser already gained with the old one.
        $granted[(int) $order->id] = (int) $order->guest_token_version;
        session([self::SESSION_KEY => array_slice($granted, -20, null, true)]);
    }

    /** Whether THIS browser session followed a valid link for the order, and the link is still valid. */
    public function hasSessionGrant(Order $order): bool
    {
        $granted = (array) session(self::SESSION_KEY, []);

        // Sessions written before the version was stored hold `true`, which reads as version 1: still honoured until the first rotation.
        return isset($granted[(int) $order->id])
            && (int) $granted[(int) $order->id] === max(1, (int) $order->guest_token_version)
            && $this->linkable($order);
    }

    private function linkable(Order $order): bool
    {
        return $order->user_id === null
            && $order->guest_email !== null
            && $order->guest_token_hash !== null
            && ($order->guest_token_expires_at === null || $order->guest_token_expires_at->isFuture());
    }

    private function compute(Order $order): string
    {
        $data = 'guest-order|'.$order->id.'|'.mb_strtolower((string) $order->guest_email).'|'.(int) $order->guest_token_version;

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, (string) config('app.key'), true)), '+/', '-_'), '=');
    }
}
