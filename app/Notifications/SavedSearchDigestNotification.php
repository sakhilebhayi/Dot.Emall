<?php

namespace App\Notifications;

use App\Models\SavedSearch;
use App\Notifications\Concerns\ChecksPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SavedSearchDigestNotification extends Notification
{
    use ChecksPreferences;

    /** @param  list<int>  $sampleProductIds */
    public function __construct(public SavedSearch $saved, public int $newCount, public array $sampleProductIds = []) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'saved_search');
    }

    private function text(): string
    {
        return $this->newCount.' new '.($this->newCount === 1 ? 'listing matches' : 'listings match').' your saved search "'.$this->saved->name.'".';
    }

    private function url(): string
    {
        return route('products.index', array_filter(['q' => $this->saved->query] + ($this->saved->filters ?? []), fn ($v) => $v !== null && $v !== []));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('New matches: '.$this->saved->name)->line($this->text())->action('See them', $this->url())
            ->line('You get this '.$this->saved->notify_frequency.' because you turned it on for this search. You can turn it off on the saved search.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['type' => 'saved_search', 'title' => 'New matches', 'message' => $this->text(), 'saved_search_id' => $this->saved->id, 'url' => $this->url()];
    }
}
