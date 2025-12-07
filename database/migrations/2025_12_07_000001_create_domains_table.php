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
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique()->comment('Main domain (e.g., example.com)');
            $table->json('allowed_subdomains')->nullable()->comment('List of allowed subdomains to crawl');
            $table->json('crawl_settings')->nullable()->comment('Domain-specific crawler settings');
            $table->boolean('is_active')->default(true)->comment('Whether crawling is enabled');
            $table->timestamp('last_crawled_at')->nullable()->comment('Last time this domain was crawled');
            $table->timestamps();

            $table->index('is_active');
            $table->index('last_crawled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};

