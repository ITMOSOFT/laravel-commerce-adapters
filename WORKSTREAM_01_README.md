# Workstream 01 — Product Catalog ↔ Product Variants

This overlay makes `catalog_product_variants` the single canonical table for Product Catalog products while retaining the Product Variants manager API.

## Architecture

- Product Catalog remains the owner of product identity, attributes and attribute values.
- The existing Product Catalog variant table remains canonical; existing `product_id`, SKU, name, price, compare-at price, active state and options are retained.
- The adapter model subclasses Product Catalog's existing `ProductVariant`, so existing code that type-hints the Catalog variant model remains compatible.
- Product Variants v1.0.1 introduces a small `ProductVariantContract`; its manager operates on that contract rather than requiring a polymorphic model. The Catalog model's existing `BelongsTo` product relation is therefore preserved.
- The adapter extends the existing table with barcode, cost, weight, default flag, sort order and a legacy source ID used for idempotent imports.
- The standalone Product Variants migration must be opt-out capable; by default the adapter disables it so a fresh Catalog-only installation does not create an unused second variant table. If other polymorphic product models need variants, set `ITMOSOFT_PRODUCT_VARIANTS_STANDALONE_MODELS_ENABLED=true`; the manager then routes Catalog products to the Catalog table and other models to the standalone table.
- The existing `product_attribute_value.product_variant_id` foreign key remains valid because variant IDs continue to refer to `catalog_product_variants`.

## Important prerequisite: Product Variants v1.0.1

The currently published Product Variants v1.0.0 provider always loads its own migration. Before this overlay can run safely, apply the upstream patch in `../upstream-product-variants/` and release Product Variants v1.0.1. The patch adds `ProductVariantContract`, per-product model overrides (`models.by_product`), manager type hints using that contract, and `product-variants.migrations.enabled` (default `true`) before loading migrations. Standalone installations retain their existing default model, migration and behavior.

## Apply to laravel-commerce-adapters

1. Copy `src/`, `database/`, and `tests/Feature/ProductCatalogVariantsAdapterTest.php` from this `adapter/` directory into the repository, preserving paths.
2. Merge `composer.workstream01.json` into the repository's `composer.json` (it preserves current dependencies and adds Product Catalog plus Product Variants v1.0.1). Do not replace the repository manifest blindly.
3. Copy `src/CommerceAdaptersServiceProvider.php` and `config/commerce-adapters.php` into the repository, replacing those two files. The provider preserves the existing eight adapters and event listeners while adding this integration.
4. Copy `tests/TestCase.php` into the repository, replacing the existing test base. It retains the existing providers and adds the two new package providers.
5. Run `composer update --with-all-dependencies`, `composer validate --no-check-publish`, and `composer test` in the package repository.

## Existing applications

Back up the database first. Deploy Product Variants v1.0.1 and this adapter together, then run the adapter migration. If the standalone `itmosoft_product_variants` table already contains rows for Product Catalog products, inspect them with:

```bash
php artisan commerce-adapters:import-product-variants --dry-run
php artisan commerce-adapters:import-product-variants
```

The importer only reads the source table and inserts into the canonical catalog table. It does not delete or modify source rows. It rejects missing catalog products, cross-product SKU collisions and inconsistent source-ID mappings. Imported source IDs are recorded on the canonical rows, making reruns idempotent even when a legacy SKU is null. The migration refuses rollback while imported source IDs remain, to avoid discarding the import mapping. Existing same-product SKU matches are linked instead of duplicated. Rows for non-catalog polymorphic product types are intentionally ignored.

## Usage

```php
use ItmoSoft\CommerceAdapters\Services\ProductCatalogVariantAdapter;
use ItmoSoft\ProductCatalog\Models\Product;

$product = Product::findOrFail(1);
$variants = app(ProductCatalogVariantAdapter::class);

$variant = $variants->create($product, [
    'name' => 'Large / Black',
    'sku' => 'TSHIRT-L-BLK',
    'barcode' => '1234567890123',
    'options' => ['size' => 'Large', 'color' => 'Black'],
    'price' => 29.99,
    'compare_at_price' => 34.99,
    'cost' => 12.50,
    'weight' => 0.350,
    'is_default' => true,
]);

$product->variants; // Existing relation, now backed by the canonical table/model.
$product->attributeValues()->attach($attributeValue->id, [
    'product_variant_id' => $variant->id,
]);
```

The adapter's `create`, `update`, `setDefault`, `activate`, `deactivate` and `delete` methods delegate to the Product Variants manager. Continue to validate attribute-option consistency and authorize variant changes in the application.

## Compatibility and boundaries

- Supports the package's declared Laravel 12/13 and PHP 8.3+ matrix, subject to running the actual CI matrix after integration.
- No existing Catalog variant table is dropped or renamed.
- Existing Product Catalog variant IDs remain unchanged.
- The old standalone variants table is not dropped; import is explicit and non-destructive. Fresh Catalog-only installs skip that table unless standalone polymorphic models are enabled.
- SKU uniqueness is enforced by a unique index within each physical table. If standalone polymorphic variants are enabled alongside Catalog variants, the same SKU could exist once in each table; enforce cross-table uniqueness in the application if your business requires it.
- `is_default` uniqueness is maintained by manager operations, not a database partial unique constraint.
- The importer currently imports Product Catalog's morph type only. Other polymorphic product models remain in the standalone table.
