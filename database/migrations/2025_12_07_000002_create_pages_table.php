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
        // Enable pgvector extension for PostgreSQL
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
            $table->text('url')->comment('Full URL of the page');
            $table->string('url_hash', 64)->comment('SHA256 hash of URL for unique constraint');
            $table->string('title')->nullable()->comment('Page title');
            $table->text('summary')->nullable()->comment('AI-generated summary');
            $table->json('keywords')->nullable()->comment('Extracted keywords');
            $table->string('page_type', 50)->nullable()->comment('Type: product, category, article, homepage, contact, other');
            $table->json('metadata')->nullable()->comment('Additional metadata from AI analysis');
            $table->unsignedInteger('depth')->default(0)->comment('Crawl depth from domain root');
            $table->unsignedInteger('inbound_links_count')->default(0)->comment('Number of pages linking to this page');
            $table->timestamp('last_crawled_at')->nullable()->comment('Last successful crawl timestamp');
            $table->longText('raw_html')->nullable()->comment('Raw HTML content');
            $table->timestamps();

            $table->unique(['domain_id', 'url_hash']);
            $table->index('page_type');
            $table->index('inbound_links_count');
            $table->index('last_crawled_at');
            $table->index('depth');
        });

        // Add vector column for embeddings (PostgreSQL pgvector)
        DB::statement('ALTER TABLE pages ADD COLUMN embedding vector(1536)');

        // Create index for vector similarity search
        DB::statement('CREATE INDEX pages_embedding_idx ON pages USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};

