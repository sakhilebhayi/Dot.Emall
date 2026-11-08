<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Services\Discovery\Track;

/** Notes a confirmed payment in the funnel log, once per order. Auto-discovered from the type-hint. */
class RecordOrderPaid
{
    public function __construct(private readonly Track $track) {}

    public function handle(OrderPaid $event): void
    {
        $this->track->orderPaid($event->order);
    }
}
