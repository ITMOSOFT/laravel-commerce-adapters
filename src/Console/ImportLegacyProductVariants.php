<?php

namespace ItmoSoft\CommerceAdapters\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ItmoSoft\CommerceAdapters\Models\CatalogProductVariant;
use ItmoSoft\ProductCatalog\Models\Product;
use RuntimeException;

class ImportLegacyProductVariants extends Command
{
    protected $signature = 'commerce-adapters:import-product-variants {--dry-run : Inspect eligible legacy rows without importing them}';

    protected $description = 'Import Product Catalog product variants from the standalone Product Variants table.';

    public function handle(): int
    {
        $source = config('product-variants.table_prefix', 'itmosoft_').'product_variants';
        $target = (new CatalogProductVariant())->getTable();

        if (! Schema::hasTable($target)) {
            throw new RuntimeException("Canonical table [{$target}] is missing; run the application migrations first.");
        }

        if (! Schema::hasTable($source)) {
            $this->info("No legacy table [{$source}] exists; nothing to import.");

            return self::SUCCESS;
        }

        $productModel = config('product-catalog.models.product', Product::class);
        $morphType = (new $productModel())->getMorphClass();
        $imported = 0;
        $alreadyPresent = 0;
        $dryRun = (bool) $this->option('dry-run');

        DB::table($source)
            ->where('product_type', $morphType)
            ->chunkById(500, function ($rows) use ($target, $productModel, $dryRun, &$imported, &$alreadyPresent): void {
                foreach ($rows as $row) {
                    $mapped = DB::table($target)->where('source_variant_id', $row->id)->first();
                    if ($mapped !== null) {
                        if ((int) $mapped->product_id !== (int) $row->product_id || $mapped->sku !== $row->sku) {
                            throw new RuntimeException("Legacy variant ID [{$row->id}] is already mapped to a different catalog variant.");
                        }
                        $alreadyPresent++;
                        continue;
                    }

                    $productExists = $productModel::query()->whereKey($row->product_id)->exists();
                    if (! $productExists) {
                        throw new RuntimeException("Legacy variant [{$row->id}] references missing catalog product [{$row->product_id}].");
                    }

                    $existing = $row->sku === null ? null : DB::table($target)->where('sku', $row->sku)->first();
                    if ($existing !== null) {
                        if ((int) $existing->product_id !== (int) $row->product_id) {
                            throw new RuntimeException("SKU [{$row->sku}] already belongs to a different catalog product; resolve it before importing.");
                        }
                        if ($existing->source_variant_id !== null && (int) $existing->source_variant_id !== (int) $row->id) {
                            throw new RuntimeException("SKU [{$row->sku}] is already linked to a different legacy variant; resolve it before importing.");
                        }
                        if (! $dryRun) {
                            DB::table($target)->where('id', $existing->id)->update(['source_variant_id' => $row->id]);
                        }
                        $alreadyPresent++;
                        continue;
                    }

                    if ($dryRun) {
                        $imported++;
                        continue;
                    }

                    DB::transaction(function () use ($row, $target): void {
                        DB::table($target)->insert([
                            'product_id' => $row->product_id,
                            'source_variant_id' => $row->id,
                            'sku' => $row->sku,
                            'barcode' => $row->barcode,
                            'name' => $row->name,
                            'options' => $row->options,
                            'price' => $row->price,
                            'compare_at_price' => $row->compare_at_price,
                            'cost' => $row->cost,
                            'weight' => $row->weight,
                            'is_active' => $row->is_active,
                            'is_default' => $row->is_default,
                            'sort_order' => $row->sort_order,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ]);
                    });
                    $imported++;
                }
            });

        $this->table(['Result', 'Count'], [
            [$dryRun ? 'Eligible rows' : 'Imported rows', $imported],
            ['Already represented by matching SKU/product', $alreadyPresent],
        ]);
        $this->info($dryRun ? 'Dry run only; no rows were written.' : 'Import completed. The source table was not modified.');

        return self::SUCCESS;
    }
}
