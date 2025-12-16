<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety: avoid failing if the table already exists in a local DB.
        if (Schema::hasTable('type_structures')) {
            return;
        }

        Schema::create('type_structures', function (Blueprint $table) {
            $table->id();

            $table->string('type')->comment('Original type string as provided by the user/system');
            $table->string('type_normalized')->comment('Normalized type string (lowercase/trim/space-collapse)');

            // Tags are stored as a JSON array of normalized strings for lookup via whereJsonContains().
            $table->jsonb('tags')->comment('Array of normalized tags/aliases (strings)');

            // Structure is a JSON object WITHOUT the "tags" key; used for indexing/search mapping.
            $table->jsonb('structure')->comment('Type-specific structure JSON (without tags)');

            $table->timestamps();

            $table->unique('type_normalized');
        });

        // Index tags for fast JSON containment lookups (PostgreSQL).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX type_structures_tags_gin_idx ON type_structures USING GIN (tags)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('type_structures');
    }
};



