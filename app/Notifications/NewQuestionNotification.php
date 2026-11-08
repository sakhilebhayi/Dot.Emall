<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductQuestion;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** In-app notice to the seller: a shopper asked about one of their products. */
class NewQuestionNotification extends Notification
{
    public function __construct(public ProductQuestion $question, public Product $product) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'new_question',
            'title' => 'New question',
            'message' => 'A shopper asked about "'.$this->product->name.'": '.Str::limit($this->question->body, 120),
            'product_id' => $this->product->id,
            'question_id' => $this->question->id,
            'url' => route('products.show', $this->product),
        ];
    }
}
