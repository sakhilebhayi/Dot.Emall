<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Str;

/**
 * To the store's people: a signed-in shopper wrote to the store from its page. The shopper's name and sign-in e-mail are in the notice
 * (the form says so before it is sent) so the store can answer; the store's own e-mail address is never shown to the shopper.
 * Seller operations are transactional (in the app and by e-mail).
 */
class StoreEnquiryNotification extends EmallNotification
{
    public function __construct(
        public Store $store,
        public string $senderName,
        public string $senderEmail,
        public string $body,
        public ?Product $product = null,
    ) {}

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $about = $this->product ? ' about "'.$this->product->name.'"' : '';

        return [
            'type' => 'store_enquiry',
            'title' => 'Message from a shopper',
            'message' => $this->senderName.' ('.$this->senderEmail.') wrote to '.$this->store->name.$about.': '.Str::limit($this->body, 400),
            'store_id' => $this->store->id,
            'product_id' => $this->product?->id,
            'url' => $this->product ? route('products.show', $this->product) : route('stores.show', $this->store),
            'action' => 'Open the page',
        ];
    }

    protected function mailFootnote(): ?string
    {
        return 'Reply to the shopper at the e-mail address shown above. Replying here does not reach them.';
    }
}
