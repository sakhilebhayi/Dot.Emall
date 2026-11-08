<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductQuestion;
use App\Services\Notifications\NotificationPreferences;

class QuestionAnsweredNotification extends EmallNotification
{
    protected string $category = NotificationPreferences::ALERTS;

    public function __construct(public ProductQuestion $question, public Product $product) {}

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'question_answered',
            'title' => 'Your question was answered',
            'message' => 'The seller answered your question about "'.$this->product->name.'".',
            'product_id' => $this->product->id,
            'question_id' => $this->question->id,
            'url' => route('products.show', $this->product),
            'action' => 'Read the answer',
        ];
    }
}
