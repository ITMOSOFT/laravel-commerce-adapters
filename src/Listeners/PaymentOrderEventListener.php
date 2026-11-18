<?php

namespace ItmoSoft\CommerceAdapters\Listeners;

use ItmoSoft\CommerceAdapters\Services\PaymentOrderAdapter;
use ItmoSoft\Payments\Events\PaymentCaptured;
use ItmoSoft\Payments\Events\PaymentFailed;
use ItmoSoft\Payments\Events\PaymentRefundUpdated;

class PaymentOrderEventListener
{
    public function __construct(protected PaymentOrderAdapter $adapter)
    {
    }

    public function handleCaptured(PaymentCaptured $event): void
    {
        $this->adapter->handleCaptured($event->payment);
    }

    public function handleFailed(PaymentFailed $event): void
    {
        $this->adapter->handleFailed($event->payment);
    }

    public function handleRefundUpdated(PaymentRefundUpdated $event): void
    {
        $this->adapter->handleRefundUpdated($event->payment);
    }
}