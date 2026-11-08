<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Whether a person wants a kind of notice on a channel. Alerts are opt-in per alert (subscribing is the consent), so
 * the default implementation allows everything; the notifications workstream binds one that reads its preference
 * table. The category names used here: back_in_stock, price_drop, saved_search, question_answered.
 */
interface NotificationPreferences
{
    public function allows(User $user, string $category, string $channel): bool;
}
