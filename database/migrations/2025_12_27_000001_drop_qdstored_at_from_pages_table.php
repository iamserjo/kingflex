<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pages', 'qdstored_at')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            // Index was created as `$table->index('qdstored_at')`
            $table->dropIndex(['qdstored_at']);
            $table->dropColumn('qdstored_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('pages', 'qdstored_at')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            $table->timestamp('qdstored_at')
                ->nullable()
                ->after('embedding_generated_at')
                ->comment('Timestamp when page vector/payload was stored in Qdrant');

            $table->index('qdstored_at');
        });
    }
};


