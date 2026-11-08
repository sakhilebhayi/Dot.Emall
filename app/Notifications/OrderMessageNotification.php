<?php

namespace App\Notifications;

use App\Models\OrderMessage;

/**
 * Someone wrote about an order. To a seller's people it is a transactional notice (a customer is waiting); to the Emall team it is the
 * report queue. The sender's name and e-mail are in the e-mail so the reader can answer; the in-app row carries the words and the order link.
 */
class OrderMessageNotification extends EmallNotification
{
    public function __construct(public int $messageId, public string $url) {}

    public function toDatabase(object $notifiable): array
    {
        $m = OrderMessage::query()->with('order')->find($this->messageId);
        $ref = $m?->order?->reference() ?? '';
        $report = $m?->kind === OrderMessage::OPERATOR;

        return array_filter([
            'type' => $report ? 'order_report' : 'order_message',
            'title' => $report ? "A problem was reported on order {$ref}" : "A customer wrote about order {$ref}",
            'message' => $m === null ? '' : "{$m->sender_name} ({$m->sender_email}) wrote: ".mb_substr($m->body, 0, 600),
            'action' => $report ? 'Open the order' : 'Open the order and reply by e-mail',
            'order_id' => $m?->order_id,
            'url' => $this->url,
        ], fn ($v) => $v !== null);
    }

    protected function mailFootnote(): ?string
    {
        return 'Reply to the sender at the address above. The buyer chose to share it with you when they wrote.';
    }
}
