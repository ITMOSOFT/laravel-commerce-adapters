<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\Checkout\Services\CheckoutManager;
use ItmoSoft\CommerceAdapters\Exceptions\CheckoutPromotionException;
use ItmoSoft\Promotions\Models\Promotion;
use ItmoSoft\Promotions\Models\Redemption;
use ItmoSoft\Promotions\PromotionService;
use InvalidArgumentException;

/** Integrates promotion calculation and redemption with pending Checkout sessions. */
class CheckoutPromotionAdapter
{
    public function __construct(
        protected PromotionService $promotions,
        protected CheckoutManager $checkout,
    ) {
    }

    /**
     * Validate and apply a promotion code to a pending checkout. This is a
     * calculation only: it does not consume a redemption or reserve usage.
     *
     * @param array<string, mixed> $context Additional promotion eligibility context.
     * @param array<int, Model> $targets Promotion-target models represented in the checkout.
     * @return array<string, mixed> Persisted promotion snapshot.
     */
    public function applyCode(
        CheckoutSession $session,
        string $code,
        array $context = [],
        ?Model $customer = null,
        array $targets = [],
    ): array {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException('A promotion code is required.');
        }
        $this->assertModelsUseDefaultConnection($session);

        return DB::transaction(function () use ($session, $code, $context, $customer, $targets): array {
            $locked = $this->lockSession($session);
            $this->assertPending($locked);
            $promotionClass = config('promotions.models.promotion', Promotion::class);
            /** @var Promotion|null $promotion */
            $promotion = $promotionClass::query()->where('code', $code)->first();
            if (! $promotion instanceof Promotion) {
                throw new CheckoutPromotionException('The supplied promotion code was not found.');
            }
            if ($promotion->getConnection()->getName() !== DB::getDefaultConnection()) {
                throw new CheckoutPromotionException('Checkout and Promotions must use the application default database connection.');
            }

            $metadata = $this->decodeArray($locked->metadata);
            $metadataKey = $this->metadataKey();
            $existing = $metadata[$metadataKey] ?? null;
            $totals = $this->decodeArray($locked->totals);
            $currentDiscount = $this->amount($totals['discount'] ?? 0, 'Checkout discount');
            if ($currentDiscount > 0 && ! is_array($existing)) {
                throw new CheckoutPromotionException('Checkout already has a discount not owned by this promotion adapter; refusing to overwrite it.');
            }

            $subtotal = $this->amount($totals['subtotal'] ?? null, 'Checkout subtotal');
            $currency = strtoupper(trim((string) $locked->currency));
            if ($currency === '') {
                throw new CheckoutPromotionException('Checkout currency is required before applying a promotion.');
            }
            $discount = $this->promotions->calculate($promotion, $subtotal, $context, $customer, $targets);
            $discount = min($subtotal, max(0, round($discount, 2)));

            $snapshot = [
                'source' => 'itmosoft-laravel-promotions',
                'promotion_id' => (int) $promotion->getKey(),
                'code' => (string) $promotion->code,
                'name' => (string) $promotion->name,
                'currency' => $currency,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'discount_amount' => number_format($discount, 2, '.', ''),
                'context' => $context,
                'customer' => $this->modelDescriptor($customer),
                'targets' => array_values(array_map(fn (mixed $target) => $this->modelDescriptor($target), $targets)),
                'applied_at' => now()->toISOString(),
                'redemption' => null,
            ];

            $this->writeTotals($locked, $discount);
            $metadata[$metadataKey] = $snapshot;
            $locked->metadata = $metadata;
            $locked->save();

            return $snapshot;
        });
    }

    /** Remove the adapter-owned promotion discount from a pending checkout. */
    public function removePromotion(CheckoutSession $session): CheckoutSession
    {
        $this->assertModelsUseDefaultConnection($session);

        return DB::transaction(function () use ($session): CheckoutSession {
            $locked = $this->lockSession($session);
            $this->assertPending($locked);
            $metadata = $this->decodeArray($locked->metadata);
            $key = $this->metadataKey();
            if (! isset($metadata[$key]) || ! is_array($metadata[$key])) {
                return $locked;
            }
            unset($metadata[$key]);
            $locked->metadata = $metadata;
            $this->writeTotals($locked, 0.0);
            $locked->save();
            return $locked;
        });
    }

    /**
     * Record the redemption when the order is being committed. Call this
     * inside the same outer DB transaction as order creation for atomicity.
     * Repeated calls with the same promotion/order reference return the
     * existing redemption.
     *
     * @param array<int, Model> $targets The same eligible target models used at application time.
     */
    public function redeemForOrder(
        CheckoutSession $session,
        string $orderReference,
        ?Model $customer = null,
        array $targets = [],
    ): Redemption {
        if (trim($orderReference) === '') {
            throw new InvalidArgumentException('An order reference is required to redeem a promotion.');
        }
        $this->assertModelsUseDefaultConnection($session);

        return DB::transaction(function () use ($session, $orderReference, $customer, $targets): Redemption {
            $locked = $this->lockSession($session);
            $metadata = $this->decodeArray($locked->metadata);
            $this->assertRedeemableState($locked, $metadata, $orderReference);
            $key = $this->metadataKey();
            $snapshot = $metadata[$key] ?? null;
            if (! is_array($snapshot) || empty($snapshot['promotion_id'])) {
                throw new CheckoutPromotionException('No applied promotion exists on this checkout session.');
            }
            $this->assertDescriptorsMatch($snapshot['customer'] ?? null, $customer, 'customer');
            $this->assertTargetDescriptorsMatch($snapshot['targets'] ?? [], $targets);

            $redemptionClass = config('promotions.models.redemption', Redemption::class);
            /** @var Redemption|null $existing */
            $existing = $redemptionClass::query()
                ->where('promotion_id', $snapshot['promotion_id'])
                ->where('order_reference', $orderReference)
                ->first();
            if ($existing instanceof Redemption) {
                return $existing;
            }

            $redemption = $this->promotions->redeem(
                promotion: (int) $snapshot['promotion_id'],
                orderReference: $orderReference,
                subtotal: (float) $snapshot['subtotal'],
                customer: $customer,
                context: is_array($snapshot['context'] ?? null) ? $snapshot['context'] : [],
                targets: $targets,
            );
            if (number_format((float) $redemption->discount_amount, 2, '.', '') !== (string) $snapshot['discount_amount']) {
                throw new CheckoutPromotionException('Promotion terms changed after checkout application. Recalculate the promotion before committing the order.');
            }

            $snapshot['redemption'] = [
                'id' => $redemption->getKey(),
                'order_reference' => $orderReference,
                'redeemed_at' => $redemption->redeemed_at?->toISOString(),
            ];
            $metadata[$key] = $snapshot;
            $locked->metadata = $metadata;
            $locked->save();

            return $redemption;
        });
    }

    protected function writeTotals(CheckoutSession $session, float $discount): void
    {
        $totals = $this->decodeArray($session->totals);
        $tax = $this->amount($totals['tax'] ?? 0, 'Checkout tax');
        if (method_exists($session, 'recalculateGrandTotal')) {
            $this->checkout->setTotals($session, $discount, $tax);
            return;
        }
        $subtotal = $this->amount($totals['subtotal'] ?? null, 'Checkout subtotal');
        $shipping = $this->amount($totals['shipping'] ?? 0, 'Checkout shipping');
        $totals['discount'] = number_format($discount, 2, '.', '');
        $totals['grand_total'] = number_format(max(0, $subtotal - $discount + $tax + $shipping), 2, '.', '');
        $session->totals = $totals;
        $session->save();
    }

    protected function lockSession(CheckoutSession $session): CheckoutSession
    {
        $class = get_class($session);
        return $class::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
    }

    protected function assertPending(CheckoutSession $session): void
    {
        if ($session->status !== 'pending') {
            throw new CheckoutPromotionException('Promotions can only be changed or redeemed for pending checkout sessions.');
        }
    }

    /** @param array<string, mixed> $metadata */
    protected function assertRedeemableState(CheckoutSession $session, array $metadata, string $orderReference): void
    {
        if ($session->status === 'pending') return;
        $orderKey = (string) config('itmosoft-commerce-adapters.checkout_orders.metadata_key', 'itmosoft_commerce_adapters');
        $orderLink = $metadata[$orderKey]['order_id'] ?? null;
        if ($session->status === 'completed' && (string) $orderLink === $orderReference) return;
        throw new CheckoutPromotionException('Promotion redemption requires a pending checkout or a completed checkout linked to the supplied order reference.');
    }

    protected function assertModelsUseDefaultConnection(CheckoutSession $session): void
    {
        $connection = DB::getDefaultConnection();
        if ($session->getConnection()->getName() !== $connection || (new Promotion())->getConnection()->getName() !== $connection) {
            throw new CheckoutPromotionException('Checkout and Promotions must use the application default database connection.');
        }
    }

    protected function metadataKey(): string
    {
        $key = (string) config('itmosoft-commerce-adapters.checkout_promotions.metadata_key', 'itmosoft_promotion');
        if ($key === '') throw new CheckoutPromotionException('The checkout promotion metadata key cannot be empty.');
        return $key;
    }

    /** @return array<string, mixed> */
    protected function decodeArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    protected function amount(mixed $value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new CheckoutPromotionException("{$label} must be a finite non-negative amount.");
        }
        return (float) $value;
    }

    /** @return array{type:string,id:int|string}|null */
    protected function modelDescriptor(mixed $model): ?array
    {
        if ($model === null) return null;
        if (! $model instanceof Model || $model->getKey() === null) {
            throw new InvalidArgumentException('Promotion customers and targets must be persisted Eloquent models.');
        }
        return ['type' => $model->getMorphClass(), 'id' => $model->getKey()];
    }

    protected function assertDescriptorsMatch(mixed $descriptor, ?Model $model, string $label): void
    {
        if ($descriptor === null && $model === null) return;
        if (! is_array($descriptor) || $model === null || $model->getMorphClass() !== ($descriptor['type'] ?? null) || (string) $model->getKey() !== (string) ($descriptor['id'] ?? '')) {
            throw new CheckoutPromotionException("The {$label} supplied for redemption must match the one used when applying the promotion.");
        }
    }

    /** @param array<int, mixed> $descriptors @param array<int, Model> $targets */
    protected function assertTargetDescriptorsMatch(array $descriptors, array $targets): void
    {
        $expected = collect($descriptors)->map(fn ($item) => is_array($item) ? (($item['type'] ?? '').':'.($item['id'] ?? '')) : '')->sort()->values()->all();
        $actual = collect($targets)->map(function ($item) {
            $descriptor = $this->modelDescriptor($item);
            return $descriptor ? $descriptor['type'].':'.$descriptor['id'] : '';
        })->sort()->values()->all();
        if ($expected !== $actual) {
            throw new CheckoutPromotionException('The promotion target models supplied for redemption must match those used when applying the promotion.');
        }
    }
}
