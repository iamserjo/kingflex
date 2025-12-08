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
            $table->text('content_with_tags_purified')->nullable()->after('raw_html')
                ->comment('Rendered page content with semantic HTML tags (h1, p, a, etc.) - extracted via Playwright');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('content_with_tags_purified');
        });
    }
};
