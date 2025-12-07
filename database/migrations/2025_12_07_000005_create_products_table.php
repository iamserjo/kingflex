<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('Product name');
            $table->decimal('price', 15, 2)->nullable()->comment('Product price');
            $table->string('currency', 10)->nullable()->default('USD')->comment('Price currency');
            $table->text('description')->nullable()->comment('Product description');
            $table->json('images')->nullable()->comment('Product images URLs');
            $table->json('attributes')->nullable()->comment('Product attributes (size, color, etc.)');
            $table->string('sku')->nullable()->comment('Stock Keeping Unit');
            $table->string('availability', 50)->nullable()->comment('Availability status');
            $table->timestamps();

            $table->index('page_id');
            $table->index('sku');
            $table->index('price');
        });

        // Add vector column for product embeddings
        DB::statement('ALTER TABLE products ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX products_embedding_idx ON products USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

