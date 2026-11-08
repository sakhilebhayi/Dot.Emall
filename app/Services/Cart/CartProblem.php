<?php

namespace App\Services\Cart;

use RuntimeException;

/** A cart change the buyer should be told about in plain words (out of stock, too many, own listing). */
class CartProblem extends RuntimeException
{
    /** The form field the message belongs to (product_id for "your own listing", quantity otherwise). */
    public function __construct(string $message, public readonly string $field = 'quantity')
    {
        parent::__construct($message);
    }
}
