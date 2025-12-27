<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pages', 'processing_started_at')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        try {
            match ($driver) {
                'pgsql' => DB::statement('DROP INDEX IF EXISTS pages_processing_started_at_index'),
                'mysql', 'mariadb' => DB::statement('DROP INDEX pages_processing_started_at_index ON pages'),
                default => null,
            };
        } catch (\Throwable) {
            // Index might not exist (or be named differently). Dropping the column is the important part.
        }

        Schema::table('pages', fn (Blueprint $table) => $table->dropColumn('processing_started_at'));
    }

    public function down(): void
    {
        if (Schema::hasColumn('pages', 'processing_started_at')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('last_crawled_at')
                ->comment('Timestamp when page processing started - used for locking');
            $table->index('processing_started_at');
        });
    }
};


