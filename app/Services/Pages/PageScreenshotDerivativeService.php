<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\Page;
use App\Services\Storage\PageAssetsStorageService;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToRetrieveMetadata;

/**
 * S3-first derived screenshots for Admin UI.
 *
 * Contract:
 * - pages.screenshot_path contains a PUBLIC S3 URL (original screenshot)
 * - derived screenshots are stored in S3 as public objects
 * - derived key includes dimensions, e.g. 1920x2300 (requested crop height)
 */
final class PageScreenshotDerivativeService
{
    private const int MAX_CROP_HEIGHT = 10000;

    private const int TARGET_WIDTH_PX = 1920;

    private const string DISK = 's3';

    private const string CACHE_CONTROL = 'public, max-age=604800';

    public function __construct(
        private readonly PageAssetsStorageService $assets,
    ) {}

    /**
     * Resolve a public URL for response.
     * If $cropHeight is null: returns original screenshot URL.
     * If $cropHeight is set: returns derived URL (creates it in S3 on-demand).
     */
    public function resolveRedirectUrl(Page $page, ?int $cropHeight): ?string
    {
        $sourceUrl = (string) ($page->screenshot_path ?? '');
        if ($sourceUrl === '') {
            return null;
        }

        $cropHeight = $this->normalizeCropHeight($cropHeight);

        // Original URL (no crop requested)
        if ($cropHeight === null) {
            return $sourceUrl;
        }

        $sourceKey = $this->assets->extractS3KeyFromPublicUrl($sourceUrl);
        $sourceBinary = $this->assets->getBinaryFromUrl($sourceUrl);
        if ($sourceBinary === '') {
            return null;
        }

        $ext = $this->normalizeExtFromKey($sourceKey);
        $contentType = match ($ext) {
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        $derivedBinary = $this->resizeToWidthAndCropFromTop(
            binary: $sourceBinary,
            ext: $ext,
            targetWidth: self::TARGET_WIDTH_PX,
            targetHeight: $cropHeight,
        );

        if ($derivedBinary === null) {
            $derivedBinary = $sourceBinary;
        }

        // Content-addressable key using MD5 of derived image
        $derivedKey = $this->derivedKeyFor($page, $cropHeight, $ext, $derivedBinary);

        // Check if already exists (deduplication)
        if (Storage::disk(self::DISK)->exists($derivedKey)) {
            return Storage::disk(self::DISK)->url($derivedKey);
        }

        Storage::disk(self::DISK)->put($derivedKey, $derivedBinary, [
            'visibility' => 'public',
            'ContentType' => $contentType,
            'CacheControl' => self::CACHE_CONTROL,
        ]);

        $expected = strlen($derivedBinary);
        try {
            if (!Storage::disk(self::DISK)->exists($derivedKey)) {
                throw new \RuntimeException("Derived screenshot does not exist after write: {$derivedKey}");
            }

            $actual = Storage::disk(self::DISK)->size($derivedKey);
            if (!is_int($actual) || $actual !== $expected) {
                throw new \RuntimeException("Derived screenshot size mismatch for {$derivedKey}: expected {$expected}, got " . (is_int($actual) ? (string) $actual : 'null'));
            }
        } catch (UnableToCheckFileExistence|UnableToRetrieveMetadata $e) {
            // HeadObject not permitted — fallback to GetObject verification
            $fetched = Storage::disk(self::DISK)->get($derivedKey);
            if ($fetched === null || strlen($fetched) !== $expected) {
                throw new \RuntimeException("Derived screenshot GetObject verification failed for {$derivedKey}", 0, $e);
            }
        }

        return Storage::disk(self::DISK)->url($derivedKey);
    }

    private function normalizeCropHeight(?int $cropHeight): ?int
    {
        if ($cropHeight === null) {
            return null;
        }

        $cropHeight = (int) $cropHeight;
        if ($cropHeight <= 0) {
            return null;
        }

        return min(self::MAX_CROP_HEIGHT, $cropHeight);
    }

    private function derivedKeyFor(Page $page, int $cropHeight, string $ext, string $contents): string
    {
        $dims = self::TARGET_WIDTH_PX . 'x' . $cropHeight;
        $hash = md5($contents);

        return "pages/{$page->id}/derived_screenshots/{$dims}/{$hash}.{$ext}";
    }

    private function normalizeExtFromKey(string $key): string
    {
        $ext = strtolower((string) pathinfo($key, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (in_array($ext, ['png', 'jpg'], true)) {
            return $ext;
        }

        return 'png';
    }

    /**
     * Resize to target width (preserving aspect ratio) and crop from top to target height.
     * Returns null if GD is unavailable or transformation fails.
     */
    private function resizeToWidthAndCropFromTop(string $binary, string $ext, int $targetWidth, int $targetHeight): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($source);
            return null;
        }

        $targetWidth = max(1, $targetWidth);
        $targetHeight = max(1, $targetHeight);

        $scale = $srcW === $targetWidth ? 1.0 : ($targetWidth / $srcW);
        $scaledH = (int) max(1, (int) round($srcH * $scale));

        $scaled = $srcW === $targetWidth
            ? $source
            : imagescale($source, $targetWidth, $scaledH);

        if ($scaled === false) {
            imagedestroy($source);
            return null;
        }

        $cropH = min($targetHeight, imagesy($scaled));
        $cropped = imagecreatetruecolor($targetWidth, $cropH);
        if ($cropped === false) {
            if ($scaled !== $source) {
                imagedestroy($scaled);
            }
            imagedestroy($source);
            return null;
        }

        // Preserve transparency for PNG
        if ($ext === 'png') {
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            $transparent = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
            imagefilledrectangle($cropped, 0, 0, $targetWidth, $cropH, $transparent);
        }

        imagecopy($cropped, $scaled, 0, 0, 0, 0, $targetWidth, $cropH);

        if ($scaled !== $source) {
            imagedestroy($scaled);
        }
        imagedestroy($source);

        $obLevel = ob_get_level();
        ob_start();
        try {
            $ext === 'jpg'
                ? imagejpeg($cropped, null, 85)
                : imagepng($cropped);
            $out = ob_get_clean();
        } finally {
            imagedestroy($cropped);
            while (ob_get_level() > $obLevel) {
                @ob_end_clean();
            }
        }

        if (!is_string($out) || $out === '') {
            return null;
        }

        return $out;
    }
}


