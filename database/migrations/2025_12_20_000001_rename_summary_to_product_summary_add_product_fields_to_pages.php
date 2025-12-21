<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename legacy column; keep the data.
        // Using raw SQL to avoid requiring doctrine/dbal.
        DB::statement('ALTER TABLE pages RENAME COLUMN summary TO product_summary');

        Schema::table('pages', function (Blueprint $table) {
            $table->longText('product_summary_specs')->nullable()->comment('AI-generated product specs (long)');
            $table->longText('product_abilities')->nullable()->comment('AI-generated product abilities / use-cases (long)');
            $table->longText('product_predicted_search_text')->nullable()->comment('AI-generated predicted search queries (5-10, comma-separated)');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn([
                'product_summary_specs',
                'product_abilities',
                'product_predicted_search_text',
            ]);
        });

        DB::statement('ALTER TABLE pages RENAME COLUMN product_summary TO summary');
    }
};


