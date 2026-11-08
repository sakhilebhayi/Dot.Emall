<?php

namespace App\Notifications;

use App\Models\Product;
use App\Services\Notifications\NotificationPreferences;

/** "It is back": sent once to a person who asked, then the alert is spent. An alert: in the app by default, e-mail when the person opts in. */
class BackInStockNotification extends EmallNotification
{
    protected string $category = NotificationPreferences::ALERTS;

    public function __construct(public Product $product) {}

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'back_in_stock',
            'title' => 'Back in stock: '.$this->product->name,
            'message' => '"'.$this->product->name.'" is back in stock ('.$this->product->stock.' available right now).',
            'product_id' => $this->product->id,
            'url' => route('products.show', $this->product),
            'action' => 'View product',
        ];
    }

    protected function mailFootnote(): ?string
    {
        return 'You asked us to tell you once when it returned. This alert is now finished.';
    }
}
