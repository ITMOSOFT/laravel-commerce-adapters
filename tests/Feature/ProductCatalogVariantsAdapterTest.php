<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ItmoSoft\CommerceAdapters\Models\CatalogProductVariant;
use ItmoSoft\CommerceAdapters\Services\ProductCatalogVariantAdapter;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\ProductCatalog\Models\Attribute;
use ItmoSoft\ProductCatalog\Models\Product;
use ItmoSoft\ProductCatalog\Models\ProductVariant as ProductCatalogVariantModel;
use ItmoSoft\ProductVariants\Models\ProductVariant as StandaloneProductVariant;
use ItmoSoft\ProductVariants\ProductVariantsManager;

class ProductCatalogVariantsAdapterTest extends TestCase
{
    public function test_product_variants_share_the_catalog_table_and_do_not_create_a_second_table(): void
    {
        $product = Product::create(['name' => 'Canvas Backpack']);
        $variant = app(ProductCatalogVariantAdapter::class)->create($product, [
            'name' => 'Black / 20L',
            'sku' => 'BAG-BLK-20',
            'barcode' => '1234567890123',
            'options' => ['color' => 'Black', 'capacity' => '20L'],
            'price' => 49.99,
            'compare_at_price' => 59.99,
            'cost' => 21.50,
            'weight' => 0.650,
            'is_default' => true,
        ]);

        $this->assertInstanceOf(CatalogProductVariant::class, $variant);
        $this->assertInstanceOf(ProductCatalogVariantModel::class, $variant);
        $this->assertSame('catalog_product_variants', $variant->getTable());
        $this->assertSame($product->id, $variant->product_id);
        $this->assertTrue($variant->product->is($product));
        $this->assertSame('1234567890123', $variant->barcode);
        $this->assertSame('21.50', $variant->cost);
        $this->assertSame('0.650', $variant->weight);
        $this->assertTrue($variant->is_default);
        $this->assertFalse(Schema::hasTable('itmosoft_product_variants'));
        $this->assertCount(1, $product->fresh()->variants);
    }

    public function test_catalog_model_override_does_not_replace_the_standalone_variant_model(): void
    {
        $this->assertSame(
            CatalogProductVariant::class,
            config('product-variants.models.by_product.'.Product::class)
        );
        $this->assertSame(StandaloneProductVariant::class, config('product-variants.models.variant'));
    }

    public function test_manager_can_change_default_variant_on_catalog_product(): void
    {
        $product = Product::create(['name' => 'Everyday Tee']);
        $adapter = app(ProductCatalogVariantAdapter::class);
        $first = $adapter->create($product, ['name' => 'Small', 'sku' => 'TEE-S', 'is_default' => true]);
        $second = $adapter->create($product, ['name' => 'Medium', 'sku' => 'TEE-M']);

        $adapter->setDefault($second);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertTrue($second->fresh()->product->is($product));
    }

    public function test_catalog_attribute_pivot_keeps_its_variant_foreign_key_contract(): void
    {
        $product = Product::create(['name' => 'T-Shirt']);
        $variant = app(ProductVariantsManager::class)->create($product, [
            'name' => 'Red / Large',
            'sku' => 'TS-RED-L',
        ]);
        $attribute = Attribute::create(['name' => 'Color', 'type' => 'select', 'is_variant' => true]);
        $value = $attribute->values()->create(['value' => 'Red', 'slug' => 'red']);

        $product->attributeValues()->attach($value->id, ['product_variant_id' => $variant->id]);

        $this->assertSame($variant->id, $product->fresh()->attributeValues()->first()->pivot->product_variant_id);
        $this->assertDatabaseHas('catalog_product_attribute_value', [
            'product_id' => $product->id,
            'attribute_value_id' => $value->id,
            'product_variant_id' => $variant->id,
        ]);
    }

    public function test_legacy_catalog_variants_can_be_imported_without_mutating_the_source(): void
    {
        Schema::create('itmosoft_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->morphs('product');
            $table->string('sku', 100)->nullable()->unique();
            $table->string('barcode', 100)->nullable();
            $table->string('name');
            $table->json('options')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $product = Product::create(['name' => 'Legacy import product']);
        $legacyId = DB::table('itmosoft_product_variants')->insertGetId([
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'sku' => null,
            'barcode' => 'LEGACY-BC',
            'name' => 'Legacy option',
            'options' => json_encode(['size' => 'XL']),
            'price' => 18.50,
            'compare_at_price' => null,
            'cost' => 7.25,
            'weight' => 0.420,
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('commerce-adapters:import-product-variants --dry-run')->assertExitCode(0);
        $this->assertDatabaseMissing('catalog_product_variants', ['source_variant_id' => $legacyId]);

        $this->artisan('commerce-adapters:import-product-variants')->assertExitCode(0);
        $this->assertDatabaseHas('catalog_product_variants', [
            'source_variant_id' => $legacyId,
            'product_id' => $product->id,
            'barcode' => 'LEGACY-BC',
        ]);
        $this->assertDatabaseHas('itmosoft_product_variants', ['id' => $legacyId]);

        $this->artisan('commerce-adapters:import-product-variants')->assertExitCode(0);
        $this->assertSame(1, DB::table('catalog_product_variants')->where('source_variant_id', $legacyId)->count());
    }

    public function test_new_variants_keep_the_catalog_belongs_to_relationship(): void
    {
        $product = Product::create(['name' => 'Legacy Product']);
        $variant = $product->variants()->create(['name' => 'Legacy option', 'sku' => 'LEGACY-1']);

        $this->assertTrue($variant->fresh()->product->is($product));
    }
}
