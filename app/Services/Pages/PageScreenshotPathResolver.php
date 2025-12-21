<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\Page;
use Illuminate\Support\Facades\Storage;

/**
 * Resolve Page screenshot_path to an absolute readable file path.
 *
 * Notes:
 * - Filesystem disk "local" in this project is rooted at storage/app/private.
 * - Stage1 historically stored screenshots under storage/app/{relative}.
 * - Some DB rows might contain absolute paths.
 */
final class PageScreenshotPathResolver
{
    public function resolveAbsolutePath(Page $page): ?string
    {
        $path = (string) ($page->screenshot_path ?? '');
        if ($path === '') {
            return null;
        }

        // 1) Preferred: Laravel local disk (storage/app/private)
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->path($path);
        }

        // 2) Legacy: stored under storage/app/{relative}
        $legacyFullPath = storage_path('app/' . ltrim($path, '/'));
        if (is_file($legacyFullPath)) {
            return $legacyFullPath;
        }

        // 3) Absolute path stored in DB
        if (str_starts_with($path, '/') && is_file($path)) {
            return $path;
        }

        return null;
    }
}


