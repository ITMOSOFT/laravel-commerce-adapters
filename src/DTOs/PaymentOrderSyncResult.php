<?php

namespace ItmoSoft\CommerceAdapters\DTOs;

use ItmoSoft\Orders\Models\Order;

final class PaymentOrderSyncResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?Order $order = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function changed(): bool
    {
        return in_array($this->outcome, ['order_processing', 'order_refunded'], true);
    }
}