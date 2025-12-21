<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Create and cache derived screenshots (e.g. cropped to a max height).
 *
 * Caching strategy:
 * - Cache maps (pageId, cropHeight, sourceSignature) -> derived relative path
 * - Derived file is stored on disk "local" (storage/app/private)
 */
final class PageScreenshotDerivativeService
{
    private const int MAX_CROP_HEIGHT = 10000;

    private const string DERIVED_DIR = 'derived/screenshots';

    public function __construct(
        private readonly PageScreenshotPathResolver $pathResolver,
    ) {}

    /**
     * Resolve a screenshot file for response.
     *
     * If $cropHeight is null, returns original file.
     * If $cropHeight is set, returns a derived file when cropping is applicable.
     *
     * @return array{absolutePath: string, mime: string, etag: string, lastModified: int}|null
     */
    public function resolveForResponse(Page $page, ?int $cropHeight): ?array
    {
        $sourcePath = $this->pathResolver->resolveAbsolutePath($page);
        if ($sourcePath === null) {
            return null;
        }

        $sourceMTime = (int) (@filemtime($sourcePath) ?: 0);
        $sourceSize = (int) (@filesize($sourcePath) ?: 0);
        $takenAt = $page->screenshot_taken_at?->timestamp ?? 0;

        $sourceSignature = hash('sha256', implode('|', [
            $sourcePath,
            (string) $sourceMTime,
            (string) $sourceSize,
            (string) $takenAt,
        ]));

        $cropHeight = $this->normalizeCropHeight($cropHeight);

        // Original file (no crop requested)
        if ($cropHeight === null) {
            $etag = $this->etagFor($sourceSignature, null);

            return [
                'absolutePath' => $sourcePath,
                'mime' => $this->detectMime($sourcePath) ?? 'application/octet-stream',
                'etag' => $etag,
                'lastModified' => $sourceMTime,
            ];
        }

        $sourceBinary = @file_get_contents($sourcePath);
        if (!is_string($sourceBinary) || $sourceBinary === '') {
            return null;
        }

        $meta = @getimagesizefromstring($sourceBinary);
        if (!is_array($meta)) {
            return null;
        }

        $mime = (string) ($meta['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        $width = (int) ($meta[0] ?? 0);
        $height = (int) ($meta[1] ?? 0);

        // No-op: already short enough
        if ($height > 0 && $height <= $cropHeight) {
            $etag = $this->etagFor($sourceSignature, $cropHeight);

            return [
                'absolutePath' => $sourcePath,
                'mime' => $mime,
                'etag' => $etag,
                'lastModified' => $sourceMTime,
            ];
        }

        $cacheKey = "page_screenshot_derived:{$page->id}:{$cropHeight}:{$sourceSignature}";
        $ttl = now()->addDays(7);

        /** @var string $derivedRelativePath */
        $derivedRelativePath = Cache::remember($cacheKey, $ttl, function () use ($page, $cropHeight, $sourceSignature, $sourceBinary, $mime, $width): string {
            $ext = $mime === 'image/png' ? 'png' : 'jpg';
            $signatureShort = substr($sourceSignature, 0, 24);
            $dir = self::DERIVED_DIR . "/page-{$page->id}";
            $relativePath = "{$dir}/crop-{$cropHeight}-{$signatureShort}.{$ext}";

            $absolutePath = Storage::disk('local')->path($relativePath);
            if (is_file($absolutePath)) {
                return $relativePath;
            }

            Storage::disk('local')->makeDirectory($dir);

            $derived = $this->cropBinaryFromTop(
                binary: $sourceBinary,
                mime: $mime,
                targetHeight: $cropHeight,
                expectedWidth: $width > 0 ? $width : null,
            );

            // If crop failed (GD missing, decode fail, etc) -> keep original bytes as derivative
            if ($derived === null) {
                $derived = $sourceBinary;
            }

            Storage::disk('local')->put($relativePath, $derived);

            return $relativePath;
        });

        $derivedAbsolutePath = Storage::disk('local')->path($derivedRelativePath);
        $derivedMTime = (int) (@filemtime($derivedAbsolutePath) ?: $sourceMTime);

        return [
            'absolutePath' => $derivedAbsolutePath,
            'mime' => $mime,
            'etag' => $this->etagFor($sourceSignature, $cropHeight),
            'lastModified' => $derivedMTime,
        ];
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

    private function etagFor(string $sourceSignature, ?int $cropHeight): string
    {
        $v = hash('sha256', $sourceSignature . '|' . ($cropHeight === null ? 'orig' : (string) $cropHeight));
        // Strong ETag format: quoted
        return '"' . $v . '"';
    }

    private function detectMime(string $path): ?string
    {
        $binary = @file_get_contents($path);
        if (!is_string($binary) || $binary === '') {
            return null;
        }
        $meta = @getimagesizefromstring($binary);
        if (!is_array($meta)) {
            return null;
        }
        $mime = (string) ($meta['mime'] ?? '');
        return $mime !== '' ? $mime : null;
    }

    /**
     * Crop image bytes from the top (y=0) to target height and return re-encoded bytes.
     * Returns null if GD is unavailable or crop fails.
     */
    private function cropBinaryFromTop(string $binary, string $mime, int $targetHeight, ?int $expectedWidth): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        // Basic sanity check (helps avoid weird GD failures on corrupt files)
        if ($expectedWidth !== null && $expectedWidth > 0 && $width > 0 && abs($expectedWidth - $width) > 2) {
            // ignore, just proceed — width mismatch isn't fatal
        }

        $targetHeight = max(1, $targetHeight);
        $cropHeight = min($targetHeight, $height);

        $cropped = imagecreatetruecolor($width, $cropHeight);
        if ($cropped === false) {
            imagedestroy($source);
            return null;
        }

        // Preserve transparency for PNG
        if ($mime === 'image/png') {
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            $transparent = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
            imagefilledrectangle($cropped, 0, 0, $width, $cropHeight, $transparent);
        }

        imagecopy($cropped, $source, 0, 0, 0, 0, $width, $cropHeight);
        imagedestroy($source);

        $obLevel = ob_get_level();
        ob_start();
        try {
            if ($mime === 'image/jpeg') {
                imagejpeg($cropped, null, 85);
            } else {
                imagepng($cropped);
            }
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


