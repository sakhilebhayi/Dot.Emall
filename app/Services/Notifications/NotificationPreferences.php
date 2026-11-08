<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Who gets what, by what channel.
 *
 *  transactional  order, payment, delivery, return and seller-operations messages. ALWAYS on, in the app and by e-mail:
 *                 a person cannot turn off "your order shipped" (and the table cannot be written to say otherwise).
 *  alerts         opt-in notices a person asked for (back in stock, price drop): in the app by default, e-mail off.
 *  marketing      OFF by default in every channel (POPIA s69): nothing goes out until someone says yes.
 */
class NotificationPreferences
{
    public const TRANSACTIONAL = 'transactional';

    public const ALERTS = 'alerts';

    public const MARKETING = 'marketing';

    /** @var array<string, array{mail: bool, in_app: bool, locked: bool}> */
    private const DEFAULTS = [
        self::TRANSACTIONAL => ['mail' => true, 'in_app' => true, 'locked' => true],
        self::ALERTS => ['mail' => false, 'in_app' => true, 'locked' => false],
        self::MARKETING => ['mail' => false, 'in_app' => false, 'locked' => false],
    ];

    /**
     * @return array<int, string> notification channels ('database', 'mail') this person receives for the category
     */
    public function channels(object $notifiable, string $category): array
    {
        if (! $notifiable instanceof User) {
            return ['mail'];
        }

        $pref = $this->get($notifiable, $category);

        return array_values(array_filter([$pref['in_app'] ? 'database' : null, $pref['mail'] && filled($notifiable->email) ? 'mail' : null]));
    }

    /**
     * @return array{mail: bool, in_app: bool, locked: bool}
     */
    public function get(User $user, string $category): array
    {
        $default = self::DEFAULTS[$category] ?? self::DEFAULTS[self::MARKETING];

        if ($default['locked']) {
            return $default;
        }

        $row = NotificationPreference::query()->where('user_id', $user->id)->where('category', $category)->first();

        return $row === null ? $default : ['mail' => $row->mail, 'in_app' => $row->in_app, 'locked' => false];
    }

    /**
     * @return array<string, array{mail: bool, in_app: bool, locked: bool}>
     */
    public function all(User $user): array
    {
        return collect(array_keys(self::DEFAULTS))->mapWithKeys(fn (string $c) => [$c => $this->get($user, $c)])->all();
    }

    /**
     * Records a choice. A locked (transactional) category cannot be changed: the call is ignored and the unchanged
     * preference returned.
     *
     * @return array{mail: bool, in_app: bool, locked: bool}
     */
    public function set(User $user, string $category, bool $mail, bool $inApp): array
    {
        if (! isset(self::DEFAULTS[$category]) || self::DEFAULTS[$category]['locked']) {
            return $this->get($user, $category);
        }

        NotificationPreference::updateOrCreate(['user_id' => $user->id, 'category' => $category], ['mail' => $mail, 'in_app' => $inApp]);

        return $this->get($user, $category);
    }
}
