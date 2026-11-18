<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\Checkout\Services\CheckoutManager;
use ItmoSoft\CommerceAdapters\Exceptions\CheckoutShippingException;
use ItmoSoft\Shipping\Models\ShippingMethod;
use ItmoSoft\Shipping\Models\ShippingZone;
use ItmoSoft\Shipping\Services\ShippingQuoteService;
use InvalidArgumentException;

/**
 * Resolves fresh Shipping quotes and selects them on pending Checkout sessions.
 * It never creates shipments or contacts external carriers.
 */
class CheckoutShippingAdapter
{
    public function __construct(
        protected ShippingQuoteService $shipping,
        protected CheckoutManager $checkout,
    ) {
    }

    /**
     * Return currently applicable shipping quotes for a pending checkout.
     * The caller must supply the packed shipment weight in the Shipping package's weight unit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function quoteForCheckout(
        CheckoutSession $session,
        int|float|string|null $weight = null,
    ): array {
        $weight = $this->resolveWeight($weight);
        $this->validateWeight($weight);
        $connection = DB::getDefaultConnection();
        $this->assertConnection($session, $connection, 'Checkout session');
        $this->assertShippingConnections($connection);
        $this->assertPending($session);

        return $this->resolveQuotes($session, (float) $weight);
    }

    /**
     * Recalculate a submitted quote and select it only if its key commercial
     * fields still match a currently available quote. Client-supplied amounts
     * are never passed directly to CheckoutManager.
     *
     * @param array<string, mixed> $quote A quote previously returned by quoteForCheckout().
     */
    public function selectQuote(
        CheckoutSession $session,
        array $quote,
        int|float|string|null $weight = null,
    ): CheckoutSession {
        $weight = $this->resolveWeight($weight);
        $this->validateWeight($weight);
        $this->validateQuoteShape($quote);

        $connection = DB::getDefaultConnection();
        $this->assertConnection($session, $connection, 'Checkout session');
        $this->assertShippingConnections($connection);

        return DB::connection($connection)->transaction(function () use ($session, $quote, $weight, $connection): CheckoutSession {
            $sessionClass = get_class($session);
            /** @var CheckoutSession $locked */
            $locked = $sessionClass::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertConnection($locked, $connection, 'Checkout session');
            $this->assertPending($locked);

            $currentQuotes = $this->resolveQuotes($locked, (float) $weight);
            $matched = null;

            foreach ($currentQuotes as $current) {
                if ($this->sameQuote($current, $quote)) {
                    $matched = $current;
                    break;
                }
            }

            if ($matched === null) {
                throw new CheckoutShippingException(
                    'The selected shipping quote is no longer available or has changed. Request fresh shipping quotes.'
                );
            }

            $destination = $this->destination($locked);
            $details = [
                'source' => 'itmosoft-laravel-shipping',
                'shipping_method_id' => (int) $matched['method_id'],
                'shipping_method_code' => (string) $matched['code'],
                'shipping_method_name' => (string) $matched['method'],
                'rate_type' => (string) $matched['rate_type'],
                'currency' => strtoupper((string) $matched['currency']),
                'amount' => $this->money($matched['amount']),
                'weight' => $this->decimal($weight),
                'subtotal' => $this->decimal($this->checkoutSubtotal($locked)),
                'destination' => $destination,
                'quoted_at' => now()->toISOString(),
            ];

            // Some published Checkout versions expose selectShipping() but call
            // recalculateGrandTotal() on the session without including the trait
            // that defines it. Prefer the package manager when the model supports
            // that contract; otherwise apply the same documented session update
            // here so this optional adapter remains compatible with those releases.
            if (method_exists($locked, 'recalculateGrandTotal')) {
                return $this->checkout->selectShipping(
                    $locked,
                    (string) $matched['code'],
                    $details['amount'],
                    $details,
                );
            }

            $totals = $this->decodeArray($locked->totals);
            $totals['shipping'] = $details['amount'];
            $subtotal = (float) ($totals['subtotal'] ?? 0);
            $discount = (float) ($totals['discount'] ?? 0);
            $tax = (float) ($totals['tax'] ?? 0);
            $shipping = (float) $details['amount'];

            $locked->shipping_method = (string) $matched['code'];
            $locked->shipping_details = $details;
            $totals['grand_total'] = number_format(
                max(0, $subtotal - $discount + $tax + $shipping),
                2,
                '.',
                '',
            );
            $locked->totals = $totals;
            $locked->save();

            return $locked;
        });
    }

    /** @return array<int, array<string, mixed>> */
    protected function resolveQuotes(CheckoutSession $session, float $weight): array
    {
        $destination = $this->destination($session);
        $currency = strtoupper((string) $session->currency);
        if ($currency === '') {
            throw new CheckoutShippingException('Checkout currency is required to calculate shipping.');
        }

        $quotes = $this->shipping->quote($destination, [
            'subtotal' => $this->checkoutSubtotal($session),
            'weight' => $weight,
            'currency' => $currency,
        ]);

        return array_values(array_map(function (array $quote): array {
            return [
                'method_id' => (int) $quote['method_id'],
                'method' => (string) $quote['method'],
                'code' => (string) $quote['code'],
                'amount' => $this->money($quote['amount']),
                'currency' => strtoupper((string) $quote['currency']),
                'rate_type' => (string) $quote['rate_type'],
            ];
        }, $quotes));
    }

    /** @return array{country:string, region?:string, postal_code?:string} */
    protected function destination(CheckoutSession $session): array
    {
        $address = $this->decodeArray($session->shipping_address);
        $country = trim((string) ($address['country'] ?? ''));
        if ($country === '') {
            throw new CheckoutShippingException('A shipping address with a country is required before requesting shipping quotes.');
        }

        $destination = ['country' => $country];
        $region = $address['region'] ?? $address['state'] ?? $address['province'] ?? null;
        $postalCode = $address['postal_code'] ?? $address['zip'] ?? $address['zip_code'] ?? null;

        if ($region !== null && trim((string) $region) !== '') {
            $destination['region'] = trim((string) $region);
        }
        if ($postalCode !== null && trim((string) $postalCode) !== '') {
            $destination['postal_code'] = trim((string) $postalCode);
        }

        return $destination;
    }

    protected function checkoutSubtotal(CheckoutSession $session): float
    {
        $totals = $this->decodeArray($session->totals);
        $subtotal = $totals['subtotal'] ?? null;
        if (! is_numeric($subtotal) || ! is_finite((float) $subtotal) || (float) $subtotal < 0) {
            throw new CheckoutShippingException('Checkout must have a valid non-negative subtotal before shipping can be quoted.');
        }

        return (float) $subtotal;
    }

    protected function resolveWeight(int|float|string|null $weight): int|float|string
    {
        return $weight ?? config('itmosoft-commerce-adapters.checkout_shipping.default_weight', 0);
    }

    protected function validateWeight(int|float|string $weight): void
    {
        if (! is_numeric($weight) || ! is_finite((float) $weight) || (float) $weight < 0) {
            throw new InvalidArgumentException('Shipment weight must be a finite non-negative number.');
        }
    }

    /** @param array<string, mixed> $quote */
    protected function validateQuoteShape(array $quote): void
    {
        foreach (['method_id', 'method', 'code', 'amount', 'currency', 'rate_type'] as $key) {
            if (! array_key_exists($key, $quote)) {
                throw new CheckoutShippingException("The supplied shipping quote is missing [{$key}]. Request fresh quotes.");
            }
        }

        if (! is_numeric($quote['method_id']) || ! is_numeric($quote['amount']) || ! is_finite((float) $quote['amount']) || (float) $quote['amount'] < 0) {
            throw new CheckoutShippingException('The supplied shipping quote has invalid identifiers or amount.');
        }
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $submitted */
    protected function sameQuote(array $current, array $submitted): bool
    {
        return (int) $current['method_id'] === (int) $submitted['method_id']
            && (string) $current['code'] === (string) $submitted['code']
            && (string) $current['rate_type'] === (string) $submitted['rate_type']
            && strtoupper((string) $current['currency']) === strtoupper((string) $submitted['currency'])
            && $current['amount'] === $this->money($submitted['amount']);
    }

    protected function assertPending(CheckoutSession $session): void
    {
        if ($session->status !== 'pending') {
            throw new CheckoutShippingException('Shipping quotes can only be requested or selected for pending checkout sessions.');
        }
    }

    protected function assertShippingConnections(string $connection): void
    {
        foreach ([new ShippingMethod(), new ShippingZone()] as $model) {
            $this->assertConnection($model, $connection, 'Shipping package');
        }
    }

    protected function assertConnection(Model $model, string $connection, string $label): void
    {
        if ($model->getConnection()->getName() !== $connection) {
            throw new CheckoutShippingException("{$label}, Checkout and Shipping must use the application's default database connection.");
        }
    }

    /** @return array<string, mixed> */
    protected function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    protected function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    protected function decimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') ?: '0';
    }
}
