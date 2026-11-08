<?php

namespace App\Notifications;

use App\Notifications\Channels\DotNotifyChannel;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notify\NotifyClient;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for every Dot.Emall notice (orders, returns, money AND the alerts, saved-search digests and answers of discovery,
 * D-51): an in-app (database) row and an e-mail on the one mail layout, filtered by the person's preferences
 * (NotificationPreferences, one table), plus Dot.Notify when its env settings exist. The e-mail is built from the same payload
 * as the in-app row, so the two can never disagree.
 *
 * A guest (an AnonymousNotifiable with only an e-mail address) gets mail only.
 */
abstract class EmallNotification extends Notification
{
    /** transactional | alerts | marketing (see NotificationPreferences) */
    protected string $category = NotificationPreferences::TRANSACTIONAL;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        $channels = app(NotificationPreferences::class)->channels($notifiable, $this->category);

        if ($this->category === NotificationPreferences::TRANSACTIONAL && app(NotifyClient::class)->enabled()) {
            $channels[] = DotNotifyChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed> type, title, message, url, plus ids
     */
    abstract public function toDatabase(object $notifiable): array;

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toDatabase($notifiable);

        // The mail layout turns Markdown into HTML. What is in a notice is text (a customer's words, a product's name), so none of it may
        // become a link, an image or a heading in an e-mail that arrives from Dot.Emall.
        $mail = (new MailMessage)
            ->subject((string) $data['title'])
            ->greeting('Hello,')
            ->line(self::plain((string) $data['message']));

        if (! empty($data['url'])) {
            $mail->action((string) ($data['action'] ?? 'View on Dot.Emall'), (string) $data['url']);
        }

        if (($footnote = $this->mailFootnote()) !== null) {
            $mail->line($footnote);
        }

        if ($notifiable instanceof AnonymousNotifiable) {
            $mail->line('This link opens your order without an account. Anyone who has this e-mail can use it, so keep it private.');
        }

        return $mail->salutation('Dot.Emall');
    }

    /** Text with every Markdown mark escaped, so the mail layout shows it exactly as written. */
    public static function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~])/', '\\\\$1', $text);
    }

    /** One closing sentence for the e-mail only (why the person is getting it). */
    protected function mailFootnote(): ?string
    {
        return null;
    }

    /**
     * @return array<string, scalar|null> what Dot.Notify is told (never more than the in-app row)
     */
    public function toNotify(object $notifiable): array
    {
        $data = $this->toDatabase($notifiable);

        return array_filter([
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,
            'url' => $data['url'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
