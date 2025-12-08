<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updates embedding column to support different dimensions.
     */
    public function up(): void
    {
        $dimensions = config('openrouter.embedding_dimensions', 1024);

        // Drop old embedding column and recreate with new dimensions
        // This will lose existing embeddings - they need to be regenerated
        DB::statement('ALTER TABLE pages DROP COLUMN IF EXISTS embedding');
        DB::statement("ALTER TABLE pages ADD COLUMN embedding vector({$dimensions})");

        // Create index for similarity search
        DB::statement('DROP INDEX IF EXISTS pages_embedding_idx');
        DB::statement('CREATE INDEX pages_embedding_idx ON pages USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pages_embedding_idx');
        DB::statement('ALTER TABLE pages DROP COLUMN IF EXISTS embedding');
        DB::statement('ALTER TABLE pages ADD COLUMN embedding vector(1536)');
    }
};

