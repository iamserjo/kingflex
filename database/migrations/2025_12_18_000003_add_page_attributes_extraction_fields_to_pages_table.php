<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('json_attributes')->nullable()->after('product_type_detected_at')
                ->comment('AI-extracted product attributes (following type_structures.structure)');

            $table->string('product_code', 128)->nullable()->after('json_attributes')
                ->comment('Extracted product code from page (may be null)');
            $table->string('sku', 128)->nullable()->after('product_code')
                ->comment('Extracted SKU from page (may be null)');
            $table->string('product_model_number', 128)->nullable()->after('sku')
                ->comment('Extracted model number from page (may be null)');

            $table->timestamp('attributes_extracted_at')->nullable()->after('product_model_number')
                ->comment('Timestamp when json_attributes/SKU/product_code/model_number extraction completed');

            $table->index('attributes_extracted_at');
            $table->index('is_product_available');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['attributes_extracted_at']);
            $table->dropIndex(['is_product_available']);

            $table->dropColumn([
                'json_attributes',
                'product_code',
                'sku',
                'product_model_number',
                'attributes_extracted_at',
            ]);
        });
    }
};




