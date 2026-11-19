# Commerce Adapters v0.9.0 — draft, do not publish until CI passes

## Release title

`v0.9.0 — Integrate Product Catalog with Product Variants`

## Release description

Adds a Product Catalog ↔ Product Variants adapter that uses `catalog_product_variants` as the canonical table for Product Catalog products.

### Added
- `CatalogProductVariant`, extending the existing Product Catalog variant model and implementing the Product Variants contract.
- `ProductCatalogVariantAdapter` for create, update, default selection, activation, deactivation and deletion through the Product Variants manager.
- Migration adding barcode, cost, weight, default state, sort order and legacy source ID to the existing Catalog variants table.
- Per-product model routing, preserving the standalone Product Variants model for other polymorphic product models.
- Explicit, dry-run-capable legacy import command for Product Catalog variants from the standalone table.
- Feature tests for shared table ownership, model compatibility, default variant behavior, attribute pivot compatibility and legacy import idempotency.

### Upgrade notes
- Requires Product Variants `^1.0.1`.
- Back up the database before migrating.
- Existing standalone rows are not moved automatically; use `commerce-adapters:import-product-variants --dry-run` to inspect and then run the importer deliberately.
- `ITMOSOFT_PRODUCT_VARIANTS_STANDALONE_MODELS_ENABLED=true` keeps the standalone table migration available for non-Catalog polymorphic models.

### Validation

Do not publish this release until the complete PHPUnit suite and Laravel 12/13 CI matrix pass.
