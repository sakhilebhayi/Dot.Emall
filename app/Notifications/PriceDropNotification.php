<?php

namespace App\Notifications;

use App\Models\Product;
use App\Services\Notifications\NotificationPreferences;
use App\Support\Shop;

/** The price is now lower than when the person asked. Quotes only recorded prices (the one price history). */
class PriceDropNotification extends EmallNotification
{
    protected string $category = NotificationPreferences::ALERTS;

    public function __construct(public Product $product, public float $was, public float $now, public ?float $lowest30 = null) {}

    private function text(): string
    {
        $text = '"'.$this->product->name.'" is now '.Shop::amount(number_format($this->now, 2, '.', '')).', down from '.Shop::amount(number_format($this->was, 2, '.', '')).' when you set the alert.';

        if ($this->lowest30 !== null && $this->lowest30 < $this->now) {
            $text .= ' Its lowest price in the last 30 days was '.Shop::amount(number_format($this->lowest30, 2, '.', '')).'.';
        }

        return $text;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'price_drop',
            'title' => 'Price drop: '.$this->product->name,
            'message' => $this->text(),
            'product_id' => $this->product->id,
            'url' => route('products.show', $this->product),
            'action' => 'View product',
        ];
    }
}
