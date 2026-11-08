<?php

namespace App\Notifications\Concerns;

use App\Contracts\NotificationPreferences;
use App\Models\User;

/**
 * Channels for a notice: always the in-app one, and e-mail when the person's preferences allow that category by
 * e-mail (the default allows it: an alert is opt-in per alert).
 */
trait ChecksPreferences
{
    /** @return array<int, string> */
    protected function channelsFor(object $notifiable, string $category): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && filled($notifiable->email) && app(NotificationPreferences::class)->allows($notifiable, $category, 'mail')) {
            $channels[] = 'mail';
        }

        return $channels;
    }
}
