<?php

namespace App\Notifications;

/**
 * Tells someone about a decision the marketplace team made about them or their listing, with the reason the operator wrote.
 * The reason is the point: a seller who is told "rejected" and nothing else cannot fix anything (UX audit, problem 13).
 */
class OperatorDecisionNotification extends EmallNotification
{
    public function __construct(
        private readonly string $kind,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $url = null,
        private readonly ?string $action = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return array_filter([
            'type' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'action' => $this->action,
        ], fn ($v) => $v !== null);
    }
}
