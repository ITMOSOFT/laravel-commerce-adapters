<?php

namespace ItmoSoft\CommerceAdapters\Models;

use ItmoSoft\ProductCatalog\Models\ProductVariant as ProductCatalogVariant;
use ItmoSoft\ProductVariants\Contracts\ProductVariantContract;

/**
 * Keeps Product Catalog's model identity and belongsTo relationship while
 * implementing the richer Product Variants manager contract.
 */
class CatalogProductVariant extends ProductCatalogVariant implements ProductVariantContract
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'cost' => 'decimal:2',
            'weight' => 'decimal:3',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ]);
    }
}
