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
        Schema::create('page_search_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('tag')->comment('Search query tag');
            $table->unsignedTinyInteger('weight')->default(50)->comment('Tag weight 1-100');
            $table->timestamps();

            $table->unique(['page_id', 'tag']);
            $table->index('tag');
            $table->index('weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_search_tags');
    }
};

