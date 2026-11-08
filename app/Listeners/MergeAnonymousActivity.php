<?php

namespace App\Listeners;

use App\Services\Discovery\RecentlyViewed;
use App\Support\Actor;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When someone signs in, what they looked at and searched for as a visitor becomes theirs. The visitor token lives in
 * the session and survives the session id regeneration that sign-in performs. Auto-discovered from the type-hint.
 */
class MergeAnonymousActivity
{
    public function __construct(private readonly RecentlyViewed $viewed) {}

    public function handle(Login $event): void
    {
        try {
            $token = Actor::sessionToken();

            if ($token !== null) {
                $this->viewed->merge($token, (int) $event->user->getAuthIdentifier());
            }
        } catch (Throwable $e) {
            Log::warning('Could not merge a visitor\'s recent activity into their account.', ['error' => class_basename($e)]);
        }
    }
}
