<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductQuestion;
use App\Notifications\Concerns\ChecksPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuestionAnsweredNotification extends Notification
{
    use ChecksPreferences;

    public function __construct(public ProductQuestion $question, public Product $product) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'question_answered');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your question was answered')
            ->line('The seller answered your question about "'.$this->product->name.'".')
            ->action('Read the answer', route('products.show', $this->product));
    }

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
        ];
    }
}
