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
        if (!Schema::hasColumn('pages', 'page_fetched_at')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('page_fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('pages', 'page_fetched_at')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('page_fetched_at')->nullable()->after('last_crawled_at')->comment('Timestamp when page content was fetched');
        });
    }
};
