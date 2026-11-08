<?php

namespace App\Notifications;

use App\Models\Product;
use App\Notifications\Concerns\ChecksPreferences;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "It is back": sent once to a person who asked, then the alert is spent. */
class BackInStockNotification extends Notification
{
    use ChecksPreferences;

    public function __construct(public Product $product) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'back_in_stock');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Back in stock: '.$this->product->name)
            ->line('"'.$this->product->name.'" is back in stock ('.$this->product->stock.' available right now).')
            ->action('View product', route('products.show', $this->product))
            ->line('You asked us to tell you once when it returned. This alert is now finished.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'back_in_stock',
            'title' => 'Back in stock',
            'message' => '"'.$this->product->name.'" is back in stock ('.$this->product->stock.' available right now).',
            'product_id' => $this->product->id,
            'url' => route('products.show', $this->product),
        ];
    }
}
