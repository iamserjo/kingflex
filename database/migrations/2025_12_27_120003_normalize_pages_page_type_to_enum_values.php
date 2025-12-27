<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize legacy lowercase values to the canonical enum set.
        // We intentionally map unknown/legacy values (article/other/...) to MAIN to keep enum casting safe.
        DB::statement(<<<'SQL'
UPDATE pages
SET page_type = CASE
    WHEN page_type IS NULL THEN NULL
    WHEN LOWER(page_type) = 'product' THEN 'PRODUCT'
    WHEN LOWER(page_type) = 'category' THEN 'CATEGORY'
    WHEN LOWER(page_type) IN ('homepage', 'home', 'main') THEN 'MAIN'
    WHEN LOWER(page_type) = 'contact' THEN 'CONTACT'
    WHEN LOWER(page_type) = 'sitemap' THEN 'SITEMAP'
    ELSE 'MAIN'
END
SQL);
    }

    public function down(): void
    {
        // Best-effort rollback to legacy lowercase values.
        DB::statement(<<<'SQL'
UPDATE pages
SET page_type = CASE
    WHEN page_type IS NULL THEN NULL
    WHEN page_type = 'PRODUCT' THEN 'product'
    WHEN page_type = 'CATEGORY' THEN 'category'
    WHEN page_type = 'MAIN' THEN 'homepage'
    WHEN page_type = 'CONTACT' THEN 'contact'
    WHEN page_type = 'SITEMAP' THEN 'sitemap'
    ELSE page_type
END
SQL);
    }
};


