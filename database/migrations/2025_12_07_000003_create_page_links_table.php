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
        Schema::create('page_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('target_page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('anchor_text')->nullable()->comment('Link anchor text');
            $table->timestamps();

            $table->unique(['source_page_id', 'target_page_id']);
            $table->index('target_page_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_links');
    }
};

