<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductQuestion;
use Illuminate\Support\Str;

/** To the seller: a shopper asked about one of their products. Seller operations are transactional (in the app and by e-mail). */
class NewQuestionNotification extends EmallNotification
{
    public function __construct(public ProductQuestion $question, public Product $product) {}

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
            'action' => 'Answer the question',
        ];
    }
}
