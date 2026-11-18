<?php

namespace ItmoSoft\CommerceAdapters\DTOs;

use ItmoSoft\Orders\Models\Order;

final class InventoryOrderSyncResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?Order $order = null,
        public readonly int $reservationsCreated = 0,
        public readonly int $reservationsReleased = 0,
        public readonly int $reservationsFulfilled = 0,
        public readonly ?string $reason = null,
    ) {
    }

    public function changed(): bool
    {
        return $this->reservationsCreated > 0
            || $this->reservationsReleased > 0
            || $this->reservationsFulfilled > 0;
    }
}
