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
        Schema::create('page_screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('path')->comment('Path to screenshot file');
            $table->string('format', 10)->default('png')->comment('Image format (png, jpeg)');
            $table->unsignedInteger('width')->nullable()->comment('Screenshot width in pixels');
            $table->unsignedInteger('height')->nullable()->comment('Screenshot height in pixels');
            $table->unsignedBigInteger('file_size')->nullable()->comment('File size in bytes');
            $table->timestamps();

            $table->index('page_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_screenshots');
    }
};

