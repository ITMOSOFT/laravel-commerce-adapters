<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('product-catalog.table_prefix', 'catalog_').'product_variants';

        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Product Catalog table [{$table}] must exist before enabling the Product Variants adapter.");
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (! Schema::hasColumn($table, 'barcode')) {
                $blueprint->string('barcode', 100)->nullable()->index();
            }
            if (! Schema::hasColumn($table, 'cost')) {
                $blueprint->decimal('cost', 12, 2)->nullable();
            }
            if (! Schema::hasColumn($table, 'weight')) {
                $blueprint->decimal('weight', 10, 3)->nullable();
            }
            if (! Schema::hasColumn($table, 'is_default')) {
                $blueprint->boolean('is_default')->default(false);
            }
            if (! Schema::hasColumn($table, 'sort_order')) {
                $blueprint->unsignedInteger('sort_order')->default(0);
            }
            if (! Schema::hasColumn($table, 'source_variant_id')) {
                $blueprint->unsignedBigInteger('source_variant_id')->nullable()->unique('catalog_variant_source_id_unique');
            }
        });
    }

    public function down(): void
    {
        $table = config('product-catalog.table_prefix', 'catalog_').'product_variants';

        if (! Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasColumn($table, 'source_variant_id') && DB::table($table)->whereNotNull('source_variant_id')->exists()) {
            throw new RuntimeException('Cannot roll back Product Catalog variants after legacy rows have been imported; reconcile imported data before rollback.');
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            if (Schema::hasColumn($table, 'source_variant_id')) {
                $blueprint->dropUnique('catalog_variant_source_id_unique');
                $blueprint->dropColumn('source_variant_id');
            }
            foreach (['barcode', 'cost', 'weight', 'is_default', 'sort_order'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
