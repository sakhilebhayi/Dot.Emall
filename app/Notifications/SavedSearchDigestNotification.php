<?php

namespace App\Notifications;

use App\Models\SavedSearch;
use App\Services\Notifications\NotificationPreferences;

class SavedSearchDigestNotification extends EmallNotification
{
    protected string $category = NotificationPreferences::ALERTS;

    /** @param  list<int>  $sampleProductIds */
    public function __construct(public SavedSearch $saved, public int $newCount, public array $sampleProductIds = []) {}

    private function text(): string
    {
        return $this->newCount.' new '.($this->newCount === 1 ? 'listing matches' : 'listings match').' your saved search "'.$this->saved->name.'".';
    }

    private function url(): string
    {
        return route('products.index', array_filter(['q' => $this->saved->query] + ($this->saved->filters ?? []), fn ($v) => $v !== null && $v !== []));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['type' => 'saved_search', 'title' => 'New matches: '.$this->saved->name, 'message' => $this->text(), 'saved_search_id' => $this->saved->id, 'url' => $this->url(), 'action' => 'See them'];
    }

    protected function mailFootnote(): ?string
    {
        return 'You get this '.$this->saved->notify_frequency.' because you turned it on for this search. You can turn it off on the saved search.';
    }
}
