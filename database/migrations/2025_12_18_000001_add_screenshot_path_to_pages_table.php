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
            $table->string('screenshot_path')->nullable()->after('content_with_tags_purified')
                ->comment('Local storage path (storage/app/...) to the latest full-page screenshot captured during Stage 1 extraction');

            $table->index('screenshot_path');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['screenshot_path']);
            $table->dropColumn('screenshot_path');
        });
    }
};

