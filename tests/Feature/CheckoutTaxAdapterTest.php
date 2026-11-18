<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\CommerceAdapters\Exceptions\CheckoutTaxException;
use ItmoSoft\CommerceAdapters\Services\CheckoutTaxAdapter;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Tax\Models\TaxClass;
use ItmoSoft\Tax\Models\TaxRate;
use ItmoSoft\Tax\Models\TaxRule;
use Illuminate\Support\Str;

class CheckoutTaxAdapterTest extends TestCase
{
    protected function makeSession(array $overrides = []): CheckoutSession
    {
        $attributes = array_replace([
            'public_id' => (string) Str::uuid(), 'cart_id' => 101, 'user_id' => 7,
            'status' => 'pending', 'currency' => 'PKR',
            'shipping_address' => ['name'=>'Buyer','address_line_1'=>'1 Main Street','city'=>'Multan','region'=>'Punjab','postal_code'=>'60000','country'=>'PK'],
            'billing_address' => ['name'=>'Buyer','address_line_1'=>'1 Main Street','city'=>'Multan','region'=>'Punjab','postal_code'=>'60000','country'=>'PK'],
            'cart_snapshot' => [['itemable_type'=>'catalog_product','itemable_id'=>42,'quantity'=>2,'line_total'=>'2000.00']],
            'totals' => ['subtotal'=>'2000.00','discount'=>'100.00','tax'=>'0.00','shipping'=>'100.00','grand_total'=>'2000.00'],
            'metadata' => [],
        ], $overrides);
        $model = new CheckoutSession();
        foreach (['shipping_address','billing_address','cart_snapshot','totals','metadata'] as $column) {
            if (is_array($attributes[$column] ?? null) && ! $model->hasCast($column)) $attributes[$column] = json_encode($attributes[$column], JSON_THROW_ON_ERROR);
        }
        return CheckoutSession::query()->create($attributes);
    }

    /** @return array<string, mixed> */
    protected function metadata(CheckoutSession $session): array
    {
        $metadata = $session->metadata;
        if (is_array($metadata)) return $metadata;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    protected function makeTaxClass(float $rate = 15.0): TaxClass
    {
        $class = TaxClass::query()->create(['name'=>'Standard VAT','code'=>'standard','is_active'=>true]);
        $taxRate = TaxRate::query()->create(['tax_class_id'=>$class->getKey(),'name'=>'VAT','rate'=>$rate,'is_active'=>true,'is_compound'=>false]);
        TaxRule::query()->create(['tax_class_id'=>$class->getKey(),'tax_rate_id'=>$taxRate->getKey(),'country'=>'PK','region'=>'Punjab','postal_code'=>null,'priority'=>10,'is_active'=>true]);
        return $class;
    }

    public function test_calculates_tax_and_applies_checkout_totals_and_snapshot(): void
    {
        $session = $this->makeSession(); $class = $this->makeTaxClass();
        $result = app(CheckoutTaxAdapter::class)->calculateForCheckout($session, $class);
        $session->refresh();
        $this->assertSame('285.00', $result['tax_amount']); // (2000 - 100) * 15%
        $this->assertSame('285.00', $session->totals['tax']);
        $this->assertSame('2285.00', $session->totals['grand_total']); // 2000 - 100 + 285 + 100
        $metadata = $this->metadata($session);
        $this->assertSame((int) $class->getKey(), $metadata['itmosoft_tax']['tax_class_id']);
    }

    public function test_can_preview_without_mutating_checkout(): void
    {
        $session = $this->makeSession(); $class = $this->makeTaxClass();
        $result = app(CheckoutTaxAdapter::class)->calculateForCheckout($session, $class, apply: false);
        $session->refresh();
        $this->assertFalse($result['applied']);
        $this->assertSame('0.00', $session->totals['tax']);
        $this->assertArrayNotHasKey('itmosoft_tax', $this->metadata($session));
    }

    public function test_uses_billing_destination_when_configured(): void
    {
        config(['itmosoft-commerce-adapters.checkout_tax.destination'=>'billing']);
        $session = $this->makeSession(['shipping_address'=>[]]); $class = $this->makeTaxClass();
        $result = app(CheckoutTaxAdapter::class)->calculateForCheckout($session, $class);
        $this->assertSame('PK', $result['destination']['country']);
    }

    public function test_accepts_explicit_destination_override(): void
    {
        $session = $this->makeSession(['shipping_address'=>[]]); $class = $this->makeTaxClass();
        $result = app(CheckoutTaxAdapter::class)->calculateForCheckout($session, $class, ['country'=>'PK','region'=>'Punjab']);
        $this->assertSame('Punjab', $result['destination']['region']);
    }

    public function test_can_include_shipping_in_taxable_base(): void
    {
        config(['itmosoft-commerce-adapters.checkout_tax.include_shipping'=>true]);
        $session = $this->makeSession(); $class = $this->makeTaxClass();
        $result = app(CheckoutTaxAdapter::class)->calculateForCheckout($session, $class);
        $this->assertSame('300.00', $result['tax_amount']); // (2000 - 100 + 100) * 15%
    }

    public function test_rejects_missing_destination_country(): void
    {
        $this->expectException(CheckoutTaxException::class);
        app(CheckoutTaxAdapter::class)->calculateForCheckout($this->makeSession(['shipping_address'=>[]]), $this->makeTaxClass());
    }

    public function test_rejects_non_pending_checkout(): void
    {
        $this->expectException(CheckoutTaxException::class);
        app(CheckoutTaxAdapter::class)->calculateForCheckout($this->makeSession(['status'=>'completed']), $this->makeTaxClass());
    }

    public function test_resolves_no_matching_rate_as_zero_tax(): void
    {
        $session = $this->makeSession(['shipping_address'=>['country'=>'US']]); $class = $this->makeTaxClass();
        $result = app(CheckoutTaxAdapter::class)->calculateForCheckout($session, $class);
        $this->assertSame('0.00', $result['tax_amount']);
    }
}
