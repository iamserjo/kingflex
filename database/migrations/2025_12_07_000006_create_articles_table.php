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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('title')->comment('Article title');
            $table->string('author')->nullable()->comment('Article author');
            $table->timestamp('published_at')->nullable()->comment('Publication date');
            $table->longText('content')->nullable()->comment('Article content');
            $table->json('tags')->nullable()->comment('Article tags');
            $table->timestamps();

            $table->index('page_id');
            $table->index('author');
            $table->index('published_at');
        });

        // Add vector column for article embeddings
        DB::statement('ALTER TABLE articles ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX articles_embedding_idx ON articles USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};

