<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use ItmoSoft\CommerceAdapters\Models\CatalogProductVariant;
use ItmoSoft\ProductCatalog\Models\Product;
use ItmoSoft\ProductVariants\Contracts\ProductVariantContract;
use ItmoSoft\ProductVariants\ProductVariantsManager;

/** Bridges Product Catalog products to the Product Variants manager. */
class ProductCatalogVariantAdapter
{
    public function __construct(private readonly ProductVariantsManager $manager)
    {
    }

    public function variants(Product $product): HasMany
    {
        return $product->variants();
    }

    public function create(Product $product, array $attributes): ProductVariantContract
    {
        return $this->manager->create($product, $attributes);
    }

    public function update(ProductVariantContract $variant, array $attributes): ProductVariantContract
    {
        $this->assertCatalogVariant($variant);

        return $this->manager->update($variant, $attributes);
    }

    public function setDefault(ProductVariantContract $variant): ProductVariantContract
    {
        $this->assertCatalogVariant($variant);

        return $this->manager->setDefault($variant);
    }

    public function activate(ProductVariantContract $variant): ProductVariantContract
    {
        $this->assertCatalogVariant($variant);

        return $this->manager->activate($variant);
    }

    public function deactivate(ProductVariantContract $variant): ProductVariantContract
    {
        $this->assertCatalogVariant($variant);

        return $this->manager->deactivate($variant);
    }

    public function delete(ProductVariantContract $variant): void
    {
        $this->assertCatalogVariant($variant);
        $this->manager->delete($variant);
    }

    private function assertCatalogVariant(ProductVariantContract $variant): void
    {
        if (! $variant instanceof CatalogProductVariant) {
            throw new InvalidArgumentException('The variant does not use the Product Catalog canonical variant model.');
        }
    }
}
