<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\Page;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToRetrieveMetadata;

/**
 * S3-only storage for Page assets (raw_html, purified content, screenshots).
 *
 * Contract (as per project decision):
 * - DB stores PUBLIC URL (string) in pages.raw_html, pages.content_with_tags_purified, pages.screenshot_path
 * - Storage is performed on disk "s3"
 * - No legacy support (no local paths, no inline HTML in DB columns)
 */
final class PageAssetsStorageService
{
    private const string DISK = 's3';

    private const string RAW_HTML_CONTENT_TYPE = 'text/html; charset=utf-8';
    private const string TEXT_CONTENT_TYPE = 'text/plain; charset=utf-8';

    private const string SCREENSHOT_CACHE_CONTROL = 'public, max-age=604800';
    private const string TEXT_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    private const int VERIFY_MAX_ATTEMPTS = 5;
    private const int VERIFY_SLEEP_MS = 250;

    /**
     * Store raw HTML as an S3 object and return its public URL.
     */
    public function storeRawHtml(Page $page, string $html): string
    {
        $key = $this->keyForPage($page, 'raw_html', 'html', $html);

        $this->putAndVerify($key, $html, [
            'visibility' => 'public',
            'ContentType' => self::RAW_HTML_CONTENT_TYPE,
            'CacheControl' => self::TEXT_CACHE_CONTROL,
        ]);

        return Storage::disk(self::DISK)->url($key);
    }

    /**
     * Store purified content as a text file and return its public URL.
     */
    public function storePurifiedContent(Page $page, string $text): string
    {
        $key = $this->keyForPage($page, 'content_with_tags_purified', 'txt', $text);

        $this->putAndVerify($key, $text, [
            'visibility' => 'public',
            'ContentType' => self::TEXT_CONTENT_TYPE,
            'CacheControl' => self::TEXT_CACHE_CONTROL,
        ]);

        return Storage::disk(self::DISK)->url($key);
    }

    /**
     * Store a screenshot from a local temporary file and return its public URL.
     *
     * @param string $ext "png" | "jpg" | "jpeg"
     * @param array{ContentType?: string, CacheControl?: string} $meta
     */
    public function storeScreenshotFromLocalFile(Page $page, string $absolutePath, string $ext, array $meta = []): string
    {
        if (!is_file($absolutePath)) {
            throw new \RuntimeException("Screenshot temp file not found: {$absolutePath}");
        }

        $bytes = file_get_contents($absolutePath);
        if ($bytes === false) {
            throw new \RuntimeException("Failed to read screenshot temp file: {$absolutePath}");
        }

        $ext = strtolower($ext);
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        $key = $this->keyForPage($page, 'screenshots', $ext, $bytes);

        $contentType = $meta['ContentType'] ?? match ($ext) {
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        $this->putAndVerify($key, $bytes, [
            'visibility' => 'public',
            'ContentType' => $contentType,
            'CacheControl' => $meta['CacheControl'] ?? self::SCREENSHOT_CACHE_CONTROL,
        ]);

        return Storage::disk(self::DISK)->url($key);
    }

    /**
     * Read S3-hosted text by public URL.
     */
    public function getTextFromUrl(string $publicUrl): string
    {
        $key = $this->extractS3KeyFromPublicUrl($publicUrl);

        return (string) Storage::disk(self::DISK)->get($key);
    }

    /**
     * Read S3-hosted binary by public URL.
     */
    public function getBinaryFromUrl(string $publicUrl): string
    {
        $key = $this->extractS3KeyFromPublicUrl($publicUrl);

        return (string) Storage::disk(self::DISK)->get($key);
    }

    /**
     * Extract the object key from a public URL.
     *
     * This assumes URLs are produced by Storage::disk('s3')->url($key).
     */
    public function extractS3KeyFromPublicUrl(string $publicUrl): string
    {
        $parts = parse_url($publicUrl);
        $path = (string) ($parts['path'] ?? '');
        $path = ltrim($path, '/');

        if ($path === '') {
            throw new \InvalidArgumentException('Invalid S3 URL (empty path).');
        }

        // Tests: Storage::fake('s3') produces local URLs like /storage/{key}
        // Keep this compatible to allow feature tests to use url() values.
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        // If bucket appears in path-style URLs, strip it.
        $bucket = (string) (config('filesystems.disks.s3.bucket') ?? '');
        if ($bucket !== '') {
            $prefix = $bucket . '/';
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
        }

        // If AWS_URL has a path prefix (e.g. https://cdn.example.com/assets), strip it.
        $baseUrl = (string) (config('filesystems.disks.s3.url') ?? '');
        if ($baseUrl !== '') {
            $baseParts = parse_url($baseUrl);
            $basePath = (string) ($baseParts['path'] ?? '');
            $basePath = trim($basePath, '/');
            if ($basePath !== '') {
                $basePrefix = $basePath . '/';
                if (str_starts_with($path, $basePrefix)) {
                    $path = substr($path, strlen($basePrefix));
                }
            }
        }

        $key = rawurldecode($path);
        if ($key === '') {
            throw new \InvalidArgumentException('Invalid S3 URL (could not extract key).');
        }

        return $key;
    }

    /**
     * Build a content-addressable S3 key for a page asset using MD5 hash.
     */
    private function keyForPage(Page $page, string $type, string $ext, string $contents): string
    {
        $hash = md5($contents);

        return "pages/{$page->id}/{$type}/{$hash}.{$ext}";
    }

    /**
     * @param array{visibility?: string, ContentType?: string, CacheControl?: string} $options
     */
    private function putAndVerify(string $key, string $contents, array $options): void
    {
        $ok = Storage::disk(self::DISK)->put($key, $contents, $options);
        if ($ok !== true) {
            throw new \RuntimeException("Failed to write S3 object: {$key}");
        }

        $expected = strlen($contents);
        $last = null;

        for ($attempt = 1; $attempt <= self::VERIFY_MAX_ATTEMPTS; $attempt++) {
            // Strategy 1: HeadObject via disk (exists + size) — fastest if permitted
            try {
                if (Storage::disk(self::DISK)->exists($key)) {
                    $actual = Storage::disk(self::DISK)->size($key);
                    if (is_int($actual) && $actual === $expected) {
                        return;
                    }
                    throw new \RuntimeException("S3 object size mismatch for {$key}: expected {$expected}, got " . (is_int($actual) ? (string) $actual : 'null'));
                }
            } catch (UnableToCheckFileExistence|UnableToRetrieveMetadata) {
                // HeadObject not permitted — fall through to GetObject
            } catch (\Throwable $e) {
                $last = $e;
                // transient 404/consistency → retry
                if ($attempt < self::VERIFY_MAX_ATTEMPTS) {
                    usleep(self::VERIFY_SLEEP_MS * 1000);
                    continue;
                }
            }

            // Strategy 2: GetObject via disk — works if HeadObject is blocked but GetObject is allowed
            try {
                $fetched = Storage::disk(self::DISK)->get($key);
                if ($fetched === null) {
                    throw new \RuntimeException("S3 GetObject returned null for {$key}");
                }
                if (strlen($fetched) !== $expected) {
                    throw new \RuntimeException("S3 object size mismatch for {$key}: expected {$expected}, got " . strlen($fetched));
                }
                return;
            } catch (\Throwable $e) {
                $last = $e;
                if ($attempt < self::VERIFY_MAX_ATTEMPTS) {
                    usleep(self::VERIFY_SLEEP_MS * 1000);
                    continue;
                }
            }
        }

        throw new \RuntimeException(
            "Unable to verify S3 object after upload: {$key}",
            0,
            $last
        );
    }

}


