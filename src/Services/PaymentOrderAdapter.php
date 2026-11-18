<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ItmoSoft\CommerceAdapters\DTOs\PaymentOrderSyncResult;
use ItmoSoft\CommerceAdapters\Exceptions\InventoryOrderSyncException;
use ItmoSoft\Orders\Models\Order;
use ItmoSoft\Orders\Services\OrderService;
use ItmoSoft\Payments\Models\Payment;

class PaymentOrderAdapter
{
    public function __construct(protected OrderService $orders)
    {
    }

    public function handleCaptured(Payment $payment): PaymentOrderSyncResult
    {
        return $this->synchronize($payment, 'capture');
    }

    public function handleRefundUpdated(Payment $payment): PaymentOrderSyncResult
    {
        return $this->synchronize($payment, 'refund');
    }

    /**
     * A failed payment attempt is retryable by default. It must not cancel
     * the order or otherwise change its lifecycle state.
     */
    public function handleFailed(Payment $payment): PaymentOrderSyncResult
    {
        return new PaymentOrderSyncResult(
            'unchanged',
            null,
            'A failed payment attempt leaves the order pending for retry or another payment method.'
        );
    }

    /**
     * Reconcile aggregate payment amounts against the linked order.
     *
     * This method is idempotent: it derives the desired order transition from
     * the current payment ledger rather than trusting a single event payload.
     */
    public function synchronize(Payment $payment, string $trigger = 'capture'): PaymentOrderSyncResult
    {
        if (! in_array($trigger, ['capture', 'refund'], true)) {
            throw new \InvalidArgumentException('Payment/order synchronization trigger must be capture or refund.');
        }

        if ($payment->order_id === null) {
            return new PaymentOrderSyncResult('ignored', null, 'The payment is not linked to an order.');
        }

        $connection = DB::getDefaultConnection();

        // Do not throw from a payment event listener: PaymentManager dispatches
        // capture/refund events inside its transaction. A configuration mismatch
        // must not roll back a gateway-confirmed payment record.
        if ($payment->getConnection()->getName() !== $connection) {
            return new PaymentOrderSyncResult('ignored', null, 'The payment does not use the default database connection.');
        }

        return DB::connection($connection)->transaction(function () use ($payment, $trigger, $connection): PaymentOrderSyncResult {
            $orderClass = config('itmosoft-orders.models.order', Order::class);

            /** @var Order|null $order */
            $order = $orderClass::query()
                ->whereKey($payment->order_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return new PaymentOrderSyncResult('ignored', null, 'The linked order could not be found.');
            }

            if ($order->getConnection()->getName() !== $connection) {
                return new PaymentOrderSyncResult('ignored', $order, 'The order does not use the default database connection.');
            }

            $payments = Payment::query()
                ->where('order_id', $order->getKey())
                ->lockForUpdate()
                ->get();

            $orderCurrency = strtoupper((string) $order->currency);
            foreach ($payments as $linkedPayment) {
                // An unsuccessful/unused payment attempt in another currency
                // has no financial effect and must not block a valid capture.
                $hasFinancialActivity = (float) $linkedPayment->captured_amount > 0
                    || (float) $linkedPayment->refunded_amount > 0;

                if (
                    $hasFinancialActivity
                    && strtoupper((string) $linkedPayment->currency) !== $orderCurrency
                ) {
                    return new PaymentOrderSyncResult(
                        'ignored',
                        $order,
                        'A captured or refunded payment currency does not match the order currency; no automatic status transition was made.'
                    );
                }
            }

            // The current Payments schema stores amounts to two decimal places.
            // Keep aggregation aligned with that existing package contract.
            $capturedTotal = round((float) $payments->sum('captured_amount'), 2);
            $refundedTotal = round((float) $payments->sum('refunded_amount'), 2);
            $orderTotal = round((float) $order->grand_total, 2);

            if ($trigger === 'capture' && config('itmosoft-commerce-adapters.payments_orders.process_on_full_capture', true)) {
                if ($order->status === 'pending' && $orderTotal > 0 && $capturedTotal >= $orderTotal) {
                    try {
                        $updated = $this->orders->changeStatus(
                            $order,
                            'processing',
                            'Payment capture total meets the order grand total.'
                        );
                    } catch (InventoryOrderSyncException $exception) {
                        // The nested order transition is rolled back, but the
                        // gateway-confirmed payment remains in the outer payment
                        // transaction for manual stock reconciliation.
                        Log::warning('Payment captured but inventory blocked order processing.', [
                            'order_id' => $order->getKey(),
                            'payment_id' => $payment->getKey(),
                            'reason' => $exception->getMessage(),
                        ]);

                        return new PaymentOrderSyncResult(
                            'unchanged',
                            $order->fresh(),
                            'Payment captured, but inventory reservation blocked order processing: '.$exception->getMessage(),
                        );
                    }

                    return new PaymentOrderSyncResult('order_processing', $updated);
                }

                return new PaymentOrderSyncResult(
                    'unchanged',
                    $order,
                    $capturedTotal < $orderTotal
                        ? 'Captured payments do not yet cover the order grand total.'
                        : 'The order is not in the pending state.'
                );
            }

            if ($trigger === 'refund' && config('itmosoft-commerce-adapters.payments_orders.refund_on_full_refund', true)) {
                if (
                    in_array($order->status, ['processing', 'completed'], true)
                    && $capturedTotal > 0
                    && $refundedTotal >= $capturedTotal
                ) {
                    $updated = $this->orders->changeStatus(
                        $order,
                        'refunded',
                        'All captured payments linked to the order have been fully refunded.'
                    );

                    return new PaymentOrderSyncResult('order_refunded', $updated);
                }

                return new PaymentOrderSyncResult(
                    'unchanged',
                    $order,
                    $order->status === 'pending' && $capturedTotal > 0 && $refundedTotal >= $capturedTotal
                        ? 'The captured amount was fully refunded before the order entered processing; manual reconciliation is required.'
                        : 'The order does not yet meet the full-refund transition conditions.'
                );
            }

            return new PaymentOrderSyncResult('unchanged', $order, 'The corresponding automatic transition is disabled.');
        });
    }
}