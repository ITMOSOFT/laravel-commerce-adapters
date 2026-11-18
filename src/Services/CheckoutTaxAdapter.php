<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\Checkout\Services\CheckoutManager;
use ItmoSoft\CommerceAdapters\Exceptions\CheckoutTaxException;
use ItmoSoft\Tax\Models\TaxClass;
use ItmoSoft\Tax\Models\TaxRate;
use ItmoSoft\Tax\Models\TaxRule;
use ItmoSoft\Tax\Services\TaxCalculator;
use ItmoSoft\Tax\Services\TaxResolver;
use InvalidArgumentException;

/** Applies Tax package calculations to a pending Checkout session. */
class CheckoutTaxAdapter
{
    public function __construct(
        protected TaxResolver $resolver,
        protected TaxCalculator $calculator,
        protected CheckoutManager $checkout,
    ) {
    }

    /**
     * Calculate and optionally apply exclusive tax to a pending checkout.
     * A tax class is explicit because Checkout cart snapshots do not contain
     * a canonical per-line tax-class mapping.
     *
     * @param array<string, mixed>|null $destination Override destination; otherwise config chooses shipping/billing.
     * @return array<string, mixed> Calculation and snapshot details.
     */
    public function calculateForCheckout(
        CheckoutSession $session,
        TaxClass|int $taxClass,
        ?array $destination = null,
        bool $apply = true,
    ): array {
        $connection = DB::getDefaultConnection();
        $this->assertConnection($session, $connection, 'Checkout');
        $this->assertTaxConnections($connection);
        $this->assertPending($session);

        return DB::connection($connection)->transaction(function () use ($session, $taxClass, $destination, $apply, $connection): array {
            $model = get_class($session);
            /** @var CheckoutSession $locked */
            $locked = $model::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $resolvedDestination = $this->destination($locked, $destination);
            $totals = $this->decodeArray($locked->totals);
            $subtotal = $this->numericAmount($totals['subtotal'] ?? null, 'Checkout subtotal');
            $discount = $this->numericAmount($totals['discount'] ?? 0, 'Checkout discount');
            $shipping = $this->numericAmount($totals['shipping'] ?? 0, 'Checkout shipping');
            if ($discount > $subtotal) {
                throw new CheckoutTaxException('Checkout discount cannot exceed subtotal.');
            }

            $includeShipping = (bool) config('itmosoft-commerce-adapters.checkout_tax.include_shipping', false);
            $taxableAmount = max(0, $subtotal - $discount + ($includeShipping ? $shipping : 0));
            $class = $taxClass instanceof TaxClass ? $taxClass : TaxClass::query()->find($taxClass);
            if (! $class instanceof TaxClass) {
                throw new CheckoutTaxException('The supplied tax class does not exist.');
            }
            if (isset($class->is_active) && ! $class->is_active) {
                throw new CheckoutTaxException('The supplied tax class is inactive.');
            }

            $rates = $this->resolver->resolve($class, $resolvedDestination);
            $scale = (int) config('itmosoft-commerce-adapters.checkout_tax.rounding_scale', 2);
            if ($scale < 0 || $scale > 6) {
                throw new InvalidArgumentException('Tax rounding scale must be between 0 and 6.');
            }
            $calculation = $this->calculator->calculate($taxableAmount, $rates, inclusive: false, scale: $scale);
            $snapshot = [
                'source' => 'itmosoft-laravel-tax',
                'tax_class_id' => (int) $class->getKey(),
                'currency' => strtoupper((string) $locked->currency),
                'destination' => $resolvedDestination,
                'taxable_amount' => $this->money($taxableAmount, $scale),
                'tax_amount' => $this->money($calculation['tax'], $scale),
                'inclusive' => false,
                'includes_shipping' => $includeShipping,
                'rates' => $calculation['breakdown'],
                'calculated_at' => now()->toISOString(),
            ];

            if ($apply) {
                $this->applyTax($locked, $discount, $calculation['tax'], $snapshot);
            }

            return $snapshot + [
                'net' => $this->money($calculation['net'], $scale),
                'gross' => $this->money($calculation['gross'], $scale),
                'applied' => $apply,
            ];
        });
    }

    /** @param array<string, mixed> $snapshot */
    protected function applyTax(CheckoutSession $session, float $discount, float $tax, array $snapshot): void
    {
        // CheckoutManager is the public write API. Retain a compatibility
        // fallback for older published Checkout models missing its totals trait.
        if (method_exists($session, 'recalculateGrandTotal')) {
            $this->checkout->setTotals($session, $discount, $tax);
        } else {
            $totals = $this->decodeArray($session->totals);
            $totals['discount'] = $this->money($discount, 2);
            $totals['tax'] = $this->money($tax, 2);
            $subtotal = (float) ($totals['subtotal'] ?? 0);
            $shipping = (float) ($totals['shipping'] ?? 0);
            $totals['grand_total'] = number_format(max(0, $subtotal - $discount + $tax + $shipping), 2, '.', '');
            $session->totals = $totals;
            $session->save();
        }

        $metadata = $this->decodeArray($session->metadata);
        $metadata['itmosoft_tax'] = $snapshot;
        $session->metadata = $metadata;
        $session->save();
    }

    /** @param array<string, mixed>|null $override @return array<string, string> */
    protected function destination(CheckoutSession $session, ?array $override): array
    {
        $source = $override;
        if ($source === null) {
            $key = config('itmosoft-commerce-adapters.checkout_tax.destination', 'shipping') === 'billing'
                ? 'billing_address' : 'shipping_address';
            $source = $this->decodeArray($session->{$key});
        }
        $country = trim((string) ($source['country'] ?? ''));
        if ($country === '') {
            throw new CheckoutTaxException('A tax destination with a country is required. Set the checkout address or pass an explicit destination.');
        }
        $result = ['country' => $country];
        $region = $source['region'] ?? $source['state'] ?? $source['province'] ?? null;
        $postal = $source['postal_code'] ?? $source['zip'] ?? $source['zip_code'] ?? null;
        if ($region !== null && trim((string) $region) !== '') $result['region'] = trim((string) $region);
        if ($postal !== null && trim((string) $postal) !== '') $result['postal_code'] = trim((string) $postal);
        return $result;
    }

    protected function assertPending(CheckoutSession $session): void
    {
        if ($session->status !== 'pending') throw new CheckoutTaxException('Tax can only be calculated for pending checkout sessions.');
    }

    protected function assertConnection(Model $model, string $connection, string $label): void
    {
        if ($model->getConnection()->getName() !== $connection) {
            throw new CheckoutTaxException("{$label} and Tax must use the application's default database connection.");
        }
    }

    protected function assertTaxConnections(string $connection): void
    {
        foreach ([new TaxClass(), new TaxRate(), new TaxRule()] as $model) $this->assertConnection($model, $connection, 'Checkout');
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

    protected function numericAmount(mixed $value, string $label): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new CheckoutTaxException("{$label} must be a finite non-negative amount.");
        }
        return (float) $value;
    }

    protected function money(mixed $value, int $scale): string
    {
        return number_format((float) $value, $scale, '.', '');
    }
}
