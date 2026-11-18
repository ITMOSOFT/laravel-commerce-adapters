<?php

namespace ItmoSoft\CommerceAdapters\Listeners;

use ItmoSoft\CommerceAdapters\Services\InventoryOrderAdapter;
use ItmoSoft\Orders\Events\OrderStatusChanged;

class InventoryOrderEventListener
{
    public function __construct(protected InventoryOrderAdapter $adapter)
    {
    }

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->from === 'pending' && $event->to === 'processing') {
            $this->adapter->reserveOrder($event->order);
            return;
        }

        if ($event->from === 'processing' && $event->to === 'completed') {
            $this->adapter->fulfillOrder($event->order);
            return;
        }

        if ($event->to === 'cancelled') {
            $this->adapter->releaseOrder($event->order);
        }
    }
}
