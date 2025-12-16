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
            $table->boolean('is_product')->nullable()->after('content_with_tags_purified')
                ->comment('AI-detected: whether the page represents a product');
            $table->boolean('is_product_available')->nullable()->after('is_product')
                ->comment('AI-detected: whether the product is available for sale (only if is_product=true)');

            $table->foreignId('product_type_id')->nullable()->after('is_product_available')
                ->constrained('type_structures')
                ->nullOnDelete()
                ->comment('FK to type_structures when product type matched by tags/type_normalized');

            $table->timestamp('product_type_detected_at')->nullable()->after('product_type_id')
                ->comment('Timestamp when product type / availability detection completed');

            $table->index('product_type_detected_at');
            $table->index('product_type_id');
            $table->index('is_product');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['product_type_detected_at']);
            $table->dropIndex(['product_type_id']);
            $table->dropIndex(['is_product']);

            $table->dropConstrainedForeignId('product_type_id');

            $table->dropColumn([
                'is_product',
                'is_product_available',
                'product_type_detected_at',
            ]);
        });
    }
};

