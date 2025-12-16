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
        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('recap_generated_at')->nullable()->after('last_crawled_at')->comment('Timestamp when recap was generated');
            $table->timestamp('embedding_generated_at')->nullable()->after('recap_generated_at')->comment('Timestamp when embedding was generated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['recap_generated_at', 'embedding_generated_at']);
        });
    }
};
