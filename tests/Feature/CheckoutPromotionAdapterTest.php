<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use Illuminate\Support\Str;
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\CommerceAdapters\Exceptions\CheckoutPromotionException;
use ItmoSoft\CommerceAdapters\Services\CheckoutPromotionAdapter;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Promotions\Models\Promotion;
use ItmoSoft\Promotions\Models\Redemption;

class CheckoutPromotionAdapterTest extends TestCase
{
    protected function makeSession(array $overrides = []): CheckoutSession
    {
        $attributes = array_replace([
            'public_id' => (string) Str::uuid(), 'cart_id' => 101, 'user_id' => 7,
            'status' => 'pending', 'currency' => 'USD',
            'shipping_address' => ['country' => 'US'], 'billing_address' => ['country' => 'US'],
            'cart_snapshot' => [['name' => 'Item', 'quantity' => 1, 'unit_price' => '100.00', 'line_total' => '100.00']],
            'totals' => ['subtotal' => '100.00', 'discount' => '0.00', 'tax' => '5.00', 'shipping' => '10.00', 'grand_total' => '115.00'],
            'metadata' => [],
        ], $overrides);

        // Checkout's lowest supported release does not cast metadata to an array.
        // Serialize only when the installed model has no JSON-compatible cast.
        $metadataCast = (new CheckoutSession())->getCasts()['metadata'] ?? null;
        if (is_array($attributes['metadata'] ?? null)
            && ! in_array($metadataCast, ['array', 'json', 'object', 'collection'], true)) {
            $attributes['metadata'] = json_encode($attributes['metadata'], JSON_THROW_ON_ERROR);
        }

        return CheckoutSession::query()->create($attributes);
    }

    protected function makePromotion(array $overrides = []): Promotion
    {
        return Promotion::query()->create(array_replace([
            'name' => 'Welcome ten percent', 'code' => 'WELCOME10', 'type' => 'percentage', 'value' => 10,
            'minimum_order_amount' => 0, 'maximum_discount_amount' => null,
            'usage_limit' => 10, 'usage_limit_per_customer' => null,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'rules' => [], 'is_active' => true,
        ], $overrides));
    }

    protected function metadata(CheckoutSession $session): array
    {
        $value = $session->metadata;
        if (is_array($value)) return $value;
        if (is_string($value)) return json_decode($value, true) ?: [];
        return [];
    }

    public function test_applies_percentage_promotion_to_checkout_totals_and_stores_snapshot(): void
    {
        $session = $this->makeSession();
        $promotion = $this->makePromotion();
        $snapshot = app(CheckoutPromotionAdapter::class)->applyCode($session, 'WELCOME10');
        $session->refresh();
        $this->assertSame((int) $promotion->getKey(), $snapshot['promotion_id']);
        $this->assertSame('10.00', $snapshot['discount_amount']);
        $this->assertSame('10.00', $session->totals['discount']);
        $this->assertSame('105.00', $session->totals['grand_total']);
        $this->assertSame('WELCOME10', $this->metadata($session)['itmosoft_promotion']['code']);
        $this->assertSame(0, Redemption::query()->count());
    }

    public function test_replaces_its_own_existing_promotion_without_stacking(): void
    {
        $session = $this->makeSession();
        $this->makePromotion();
        $this->makePromotion(['name' => 'Fixed five', 'code' => 'FIVE', 'type' => 'fixed', 'value' => 5]);
        $adapter = app(CheckoutPromotionAdapter::class);
        $adapter->applyCode($session, 'WELCOME10');
        $snapshot = $adapter->applyCode($session->fresh(), 'FIVE');
        $session->refresh();
        $this->assertSame('5.00', $snapshot['discount_amount']);
        $this->assertSame('5.00', $session->totals['discount']);
        $this->assertSame('110.00', $session->totals['grand_total']);
    }

    public function test_removes_applied_promotion_and_restores_grand_total(): void
    {
        $session = $this->makeSession();
        $this->makePromotion();
        $adapter = app(CheckoutPromotionAdapter::class);
        $adapter->applyCode($session, 'WELCOME10');
        $adapter->removePromotion($session->fresh());
        $session->refresh();
        $this->assertSame('0.00', $session->totals['discount']);
        $this->assertSame('115.00', $session->totals['grand_total']);
        $this->assertArrayNotHasKey('itmosoft_promotion', $this->metadata($session));
    }

    public function test_rejects_unknown_code(): void
    {
        $this->expectException(CheckoutPromotionException::class);
        app(CheckoutPromotionAdapter::class)->applyCode($this->makeSession(), 'NOPE');
    }

    public function test_rejects_non_pending_checkout(): void
    {
        $this->makePromotion();
        $this->expectException(CheckoutPromotionException::class);
        app(CheckoutPromotionAdapter::class)->applyCode($this->makeSession(['status' => 'completed']), 'WELCOME10');
    }

    public function test_refuses_to_overwrite_discount_not_owned_by_adapter(): void
    {
        $this->makePromotion();
        $this->expectException(CheckoutPromotionException::class);
        app(CheckoutPromotionAdapter::class)->applyCode($this->makeSession(['totals' => [
            'subtotal' => '100.00', 'discount' => '3.00', 'tax' => '5.00', 'shipping' => '10.00', 'grand_total' => '112.00',
        ]]), 'WELCOME10');
    }

    public function test_redemption_is_recorded_only_when_order_is_committed_and_is_idempotent(): void
    {
        $session = $this->makeSession();
        $this->makePromotion();
        $adapter = app(CheckoutPromotionAdapter::class);
        $adapter->applyCode($session, 'WELCOME10');
        $first = $adapter->redeemForOrder($session->fresh(), 'ORDER-100');
        $second = $adapter->redeemForOrder($session->fresh(), 'ORDER-100');
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Redemption::query()->count());
        $this->assertSame('10.00', $first->discount_amount);
    }

    public function test_rejects_redemption_if_promotion_discount_changed_after_application(): void
    {
        $session = $this->makeSession();
        $promotion = $this->makePromotion();
        $adapter = app(CheckoutPromotionAdapter::class);
        $adapter->applyCode($session, 'WELCOME10');
        $promotion->update(['value' => 20]);
        $this->expectException(CheckoutPromotionException::class);
        $adapter->redeemForOrder($session->fresh(), 'ORDER-102');
    }

    public function test_redemption_checks_usage_limit_even_if_checkout_calculation_previously_succeeded(): void
    {
        $session = $this->makeSession();
        $promotion = $this->makePromotion(['usage_limit' => 1]);
        $adapter = app(CheckoutPromotionAdapter::class);
        $adapter->applyCode($session, 'WELCOME10');
        Redemption::query()->create([
            'promotion_id' => $promotion->getKey(), 'customer_type' => null, 'customer_id' => null,
            'order_reference' => 'OTHER-ORDER', 'subtotal' => 100, 'discount_amount' => 10,
            'context' => [], 'redeemed_at' => now(),
        ]);
        $this->expectException(\ItmoSoft\Promotions\Exceptions\PromotionException::class);
        $adapter->redeemForOrder($session->fresh(), 'ORDER-101');
    }
}
