<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use Illuminate\Support\Facades\Event;
use ItmoSoft\CommerceAdapters\Services\PaymentOrderAdapter;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Orders\Models\Order;
use ItmoSoft\Orders\Services\OrderService;
use ItmoSoft\Payments\Events\PaymentCaptured;
use ItmoSoft\Payments\Models\Payment;
use ItmoSoft\Payments\Services\PaymentManager;

class PaymentOrderAdapterTest extends TestCase
{
    protected function makeOrder(string $total = '10.00'): Order
    {
        return app(OrderService::class)->create([
            'currency' => 'USD',
            'subtotal' => $total,
            'discount_total' => '0.00',
            'tax_total' => '0.00',
            'shipping_total' => '0.00',
            'grand_total' => $total,
            'items' => [[
                'name' => 'Test item',
                'quantity' => 1,
                'unit_price' => $total,
                'line_total' => $total,
            ]],
        ]);
    }

    protected function makePayment(Order $order, string $amount = '10.00', string $currency = 'USD'): Payment
    {
        return app(PaymentManager::class)->create([
            'order_id' => $order->getKey(),
            'amount' => $amount,
            'currency' => $currency,
            'gateway' => 'test',
        ]);
    }

    public function test_full_capture_moves_pending_order_to_processing(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        app(PaymentManager::class)->capture($payment);

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(2, $order->fresh()->statusHistory()->count());
    }

    public function test_partial_capture_does_not_process_until_order_total_is_covered(): void
    {
        $order = $this->makeOrder('10.00');
        $payment = $this->makePayment($order);

        app(PaymentManager::class)->capture($payment, ['amount' => '4.00']);
        $this->assertSame('pending', $order->fresh()->status);

        app(PaymentManager::class)->capture($payment->fresh(), ['amount' => '6.00']);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_failed_payment_attempt_does_not_cancel_or_advance_order(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order);

        app(PaymentManager::class)->fail($payment, ['message' => 'Card declined']);

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_partial_refund_keeps_order_processing_and_full_refund_marks_refunded(): void
    {
        $order = $this->makeOrder();
        $manager = app(PaymentManager::class);
        $payment = $this->makePayment($order);
        $payment = $manager->capture($payment);

        $payment = $manager->recordRefund($payment, ['amount' => '3.00']);
        $this->assertSame('processing', $order->fresh()->status);

        $manager->recordRefund($payment->fresh(), ['amount' => '7.00']);
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_multiple_payments_are_aggregated_before_processing_or_refunding_order(): void
    {
        $order = $this->makeOrder('10.00');
        $manager = app(PaymentManager::class);
        $first = $this->makePayment($order, '5.00');
        $second = $this->makePayment($order, '5.00');

        $first = $manager->capture($first);
        $this->assertSame('pending', $order->fresh()->status);

        $second = $manager->capture($second);
        $this->assertSame('processing', $order->fresh()->status);

        $first = $manager->recordRefund($first->fresh(), ['amount' => '5.00']);
        $this->assertSame('processing', $order->fresh()->status);

        $manager->recordRefund($second->fresh(), ['amount' => '5.00']);
        $this->assertSame('refunded', $order->fresh()->status);
    }

    public function test_currency_mismatch_does_not_rollback_payment_or_change_order(): void
    {
        $order = $this->makeOrder();
        $payment = $this->makePayment($order, '10.00', 'EUR');

        $captured = app(PaymentManager::class)->capture($payment);

        $this->assertSame('captured', $captured->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_failed_attempt_in_another_currency_does_not_block_valid_capture(): void
    {
        $order = $this->makeOrder();
        $manager = app(PaymentManager::class);
        $failedAttempt = $this->makePayment($order, '10.00', 'EUR');
        $manager->fail($failedAttempt, ['message' => 'Declined']);

        $validAttempt = $this->makePayment($order, '10.00', 'USD');
        $manager->capture($validAttempt);

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_cancelled_order_is_not_reopened_by_late_capture(): void
    {
        $order = $this->makeOrder();
        $order = app(OrderService::class)->changeStatus($order, 'cancelled', 'Cancelled by operator');
        $payment = $this->makePayment($order);

        app(PaymentManager::class)->capture($payment);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_payment_without_order_link_is_ignored(): void
    {
        $payment = app(PaymentManager::class)->create([
            'amount' => '10.00',
            'currency' => 'USD',
            'gateway' => 'test',
        ]);

        $captured = app(PaymentManager::class)->capture($payment);

        $this->assertSame('captured', $captured->fresh()->status);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_repeated_capture_event_does_not_duplicate_order_transition(): void
    {
        $order = $this->makeOrder();
        $payment = app(PaymentManager::class)->capture($this->makePayment($order));

        Event::dispatch(new PaymentCaptured($payment->fresh()));

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(2, $order->fresh()->statusHistory()->count());
    }

    public function test_manual_synchronization_reports_when_capture_is_insufficient(): void
    {
        $order = $this->makeOrder();
        $payment = app(PaymentManager::class)->capture(
            $this->makePayment($order),
            ['amount' => '2.00']
        );

        $result = app(PaymentOrderAdapter::class)->handleCaptured($payment);

        $this->assertSame('unchanged', $result->outcome);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertFalse($result->changed());
    }
}