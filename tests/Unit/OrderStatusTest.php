<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_only_pending_and_processing_lines_can_be_cancelled(): void
    {
        $this->assertTrue(OrderStatus::Pending->isCancellable());
        $this->assertTrue(OrderStatus::Processing->isCancellable());
        $this->assertFalse(OrderStatus::Shipped->isCancellable());
        $this->assertFalse(OrderStatus::Delivered->isCancellable());
        $this->assertFalse(OrderStatus::Cancelled->isCancellable());
    }

    public function test_a_seller_can_only_move_forward_and_cancel_only_before_shipping(): void
    {
        $this->assertContains(OrderStatus::Cancelled, OrderStatus::Pending->sellerTransitions());
        $this->assertContains(OrderStatus::Cancelled, OrderStatus::Processing->sellerTransitions());
        $this->assertNotContains(OrderStatus::Cancelled, OrderStatus::Shipped->sellerTransitions());
        $this->assertNotContains(OrderStatus::Processing, OrderStatus::Shipped->sellerTransitions());
        $this->assertSame([], OrderStatus::Delivered->sellerTransitions());
        $this->assertSame([], OrderStatus::Cancelled->sellerTransitions());
    }

    public function test_only_lines_still_to_ship_need_shipping_details(): void
    {
        $this->assertTrue(OrderStatus::Pending->needsShipping());
        $this->assertTrue(OrderStatus::Processing->needsShipping());
        $this->assertTrue(OrderStatus::Shipped->needsShipping());
        $this->assertFalse(OrderStatus::Delivered->needsShipping());
        $this->assertFalse(OrderStatus::Cancelled->needsShipping());
    }

    public function test_derive_ignores_cancelled_lines(): void
    {
        $this->assertSame(OrderStatus::Cancelled, OrderStatus::derive([OrderStatus::Cancelled, OrderStatus::Cancelled]));
        $this->assertSame(OrderStatus::Delivered, OrderStatus::derive([OrderStatus::Delivered, OrderStatus::Cancelled]));
        $this->assertSame(OrderStatus::Shipped, OrderStatus::derive([OrderStatus::Delivered, OrderStatus::Shipped, OrderStatus::Cancelled]));
        $this->assertSame(OrderStatus::Processing, OrderStatus::derive([OrderStatus::Delivered, OrderStatus::Pending]));
        $this->assertSame(OrderStatus::Pending, OrderStatus::derive([OrderStatus::Pending, OrderStatus::Cancelled]));
    }

    public function test_values_are_exactly_the_five_legacy_strings(): void
    {
        $this->assertSame(['pending', 'processing', 'shipped', 'delivered', 'cancelled'], OrderStatus::values());
    }

    public function test_allocate_splits_to_the_exact_cent(): void
    {
        $shares = Money::allocate(1000, ['a' => 3333, 'b' => 3333, 'c' => 3334]);

        $this->assertSame(1000, array_sum($shares));
        $this->assertSame(['a' => 333, 'b' => 333, 'c' => 334], $shares);
    }

    public function test_allocate_with_nothing_to_split_returns_zeros(): void
    {
        $this->assertSame(['a' => 0], Money::allocate(0, ['a' => 100]));
        $this->assertSame(['a' => 0], Money::allocate(100, ['a' => 0]));
        $this->assertSame([], Money::allocate(100, []));
    }

    public function test_discount_cents_caps_fixed_discounts_and_rounds_percent(): void
    {
        $this->assertSame(1000, Money::discountCents('percent', 10, 10000));
        $this->assertSame(500, Money::discountCents('fixed', 20, 500));
        $this->assertSame(0, Money::discountCents('percent', 1, 10));
    }
}
