<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Adjust embedding column to match configured embedding dimensions
        $dimensions = (int) config('openrouter.embedding_dimensions', 2000);

        // Drop and recreate embedding column with new dimension
        DB::statement('DROP INDEX IF EXISTS pages_embedding_idx');
        DB::statement('ALTER TABLE pages DROP COLUMN IF EXISTS embedding');
        DB::statement("ALTER TABLE pages ADD COLUMN embedding vector({$dimensions})");
        DB::statement('CREATE INDEX pages_embedding_idx ON pages USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to previous default (1024) if needed
        DB::statement('DROP INDEX IF EXISTS pages_embedding_idx');
        DB::statement('ALTER TABLE pages DROP COLUMN IF EXISTS embedding');
        DB::statement('ALTER TABLE pages ADD COLUMN embedding vector(1024)');
        DB::statement('CREATE INDEX pages_embedding_idx ON pages USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }
};
