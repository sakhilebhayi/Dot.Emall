<?php

namespace App\Notifications;

use App\Models\Product;
use App\Notifications\Concerns\ChecksPreferences;
use App\Support\Shop;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The price is now lower than when the person asked. Quotes only recorded prices. */
class PriceDropNotification extends Notification
{
    use ChecksPreferences;

    public function __construct(public Product $product, public float $was, public float $now, public ?float $lowest30 = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, 'price_drop');
    }

    private function text(): string
    {
        $text = '"'.$this->product->name.'" is now '.Shop::amount(number_format($this->now, 2, '.', '')).', down from '.Shop::amount(number_format($this->was, 2, '.', '')).' when you set the alert.';

        if ($this->lowest30 !== null && $this->lowest30 < $this->now) {
            $text .= ' Its lowest price in the last 30 days was '.Shop::amount(number_format($this->lowest30, 2, '.', '')).'.';
        }

        return $text;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Price drop: '.$this->product->name)->line($this->text())->action('View product', route('products.show', $this->product));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['type' => 'price_drop', 'title' => 'Price drop', 'message' => $this->text(), 'product_id' => $this->product->id, 'url' => route('products.show', $this->product)];
    }
}
