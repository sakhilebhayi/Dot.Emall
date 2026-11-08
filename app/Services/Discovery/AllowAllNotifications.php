<?php

namespace App\Services\Discovery;

use App\Contracts\NotificationPreferences;
use App\Models\User;

/** Alerts are opt-in per alert, so until a preference store is bound every channel is allowed. */
class AllowAllNotifications implements NotificationPreferences
{
    public function allows(User $user, string $category, string $channel): bool
    {
        return true;
    }
}
