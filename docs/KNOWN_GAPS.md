# Known gaps

1. Payment amount precision: the current Payments package stores `decimal(14,2)` and uses floats. Orders stores totals to four decimal places. The adapter rounds aggregate comparisons to two decimal places to match Payments; this is not a replacement for a shared Money value object and currency-exponent-aware arithmetic.
2. Payment cancellation: Payments does not currently emit a cancellation event. No order transition is inferred from a cancelled payment.
3. Failed attempts: failures leave the order pending to allow retry. Applications that want to cancel after a retry limit must implement that policy explicitly.
4. Partial refunds: no dedicated partially-refunded order status exists, so partial refunds leave order status unchanged.
5. Refund before full collection: if the order is still pending when all captured funds are refunded, the adapter does not force an invalid pending → refunded transition. Manual reconciliation is required.
6. Multi-currency: linked payments with a currency different from the order currency are not automatically reconciled.
7. External gateway operations: this adapter does not call a gateway or verify provider webhook signatures.
8. Event timing: existing package events are synchronous. External listeners should defer side effects until after commit.
9. Concurrency: SQLite tests do not prove production row-lock behavior. Test with the target database engine.
10. No payment allocation across multiple orders or one payment shared by several orders.
