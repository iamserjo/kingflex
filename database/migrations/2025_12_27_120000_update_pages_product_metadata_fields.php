<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Renames (keep data). Use raw SQL to avoid requiring doctrine/dbal.
        DB::statement('ALTER TABLE pages RENAME COLUMN is_used TO is_product_used');
        DB::statement('ALTER TABLE pages RENAME COLUMN product_code TO product_original_article');

        Schema::table('pages', function (Blueprint $table): void {
            // Drop legacy/unused columns
            $table->dropColumn([
                'sku',
                'product_summary_specs',
                'product_abilities',
                'product_predicted_search_text',
            ]);

            // New field: when product metadata extraction (article/model/condition/etc.) completed
            $table->timestamp('product_metadata_extracted_at')
                ->nullable()
                ->after('product_model_number')
                ->comment('Timestamp when product metadata extraction completed');

            $table->index('product_metadata_extracted_at');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropIndex(['product_metadata_extracted_at']);
            $table->dropColumn('product_metadata_extracted_at');

            // Best-effort rollback: restore columns (data cannot be restored)
            $table->string('sku', 128)->nullable()->after('product_original_article')
                ->comment('Extracted SKU from page (may be null)');
            $table->longText('product_summary_specs')->nullable()->comment('AI-generated product specs (long)');
            $table->longText('product_abilities')->nullable()->comment('AI-generated product abilities / use-cases (long)');
            $table->longText('product_predicted_search_text')->nullable()->comment('AI-generated predicted search queries (5-10, comma-separated)');
        });

        DB::statement('ALTER TABLE pages RENAME COLUMN is_product_used TO is_used');
        DB::statement('ALTER TABLE pages RENAME COLUMN product_original_article TO product_code');
    }
};


