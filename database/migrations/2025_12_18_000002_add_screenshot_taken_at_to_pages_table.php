<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('screenshot_taken_at')->nullable()->after('screenshot_path')
                ->comment('Timestamp when the latest screenshot_path was captured during Stage 1 extraction');

            $table->index('screenshot_taken_at');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['screenshot_taken_at']);
            $table->dropColumn('screenshot_taken_at');
        });
    }
};

