<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('Category name');
            $table->text('description')->nullable()->comment('Category description');
            $table->string('parent_category')->nullable()->comment('Parent category name');
            $table->unsignedInteger('products_count')->nullable()->comment('Number of products in category');
            $table->timestamps();

            $table->index('page_id');
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

