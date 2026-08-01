<?php

namespace App\Notifications;

use App\Models\Review;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to a product's store owner
 * the moment a buyer leaves a review. Dispatched from
 * App\Http\Controllers\ReviewController::store().
 */
class NewReviewNotification extends Notification
{
    public function __construct(public Review $review)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $product = $this->review->product;

        return [
            'type'       => 'new_review',
            'title'      => 'New review posted',
            'message'    => "{$this->review->user->name} left a {$this->review->rating}-star review on \"{$product->name}\".",
            'product_id' => $product->id,
            'review_id'  => $this->review->id,
            'url'        => route('products.show', $product),
        ];
    }
}
