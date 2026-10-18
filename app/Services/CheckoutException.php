<?php

namespace App\Services;

use RuntimeException;

/**
 * A checkout that cannot proceed for a reason the buyer should be told
 * about in plain words (stock changed, bad promo code, empty cart).
 */
class CheckoutException extends RuntimeException {}
