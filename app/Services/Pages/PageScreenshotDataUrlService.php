<?php

declare(strict_types=1);

namespace App\Services\Pages;

use App\Models\Page;
use App\Services\Storage\PageAssetsStorageService;

/**
 * Resolve a Page screenshot_path to a data URL suitable for OpenAI-compatible image_url payload.
 *
 * Crops (optionally) when GD is available to reduce prompt size.
 */
final class PageScreenshotDataUrlService
{
    private const int DEFAULT_TOP_CROP_PX = 0;
    private const int DEFAULT_TARGET_HEIGHT_PX = 3500;

    public function __construct(
        private readonly PageAssetsStorageService $assets,
    ) {}

    public function forPage(Page $page, int $topCropPx = self::DEFAULT_TOP_CROP_PX, int $targetHeightPx = self::DEFAULT_TARGET_HEIGHT_PX): ?string
    {
        $url = (string) ($page->screenshot_path ?? '');
        if ($url === '') {
            return null;
        }

        try {
            $binary = $this->assets->getBinaryFromUrl($url);
        } catch (\Throwable) {
            return null;
        }

        return $this->toCroppedDataUrl($binary, $topCropPx, $targetHeightPx);
    }

    /**
     * Convert screenshot bytes to a data URL after optional cropping.
     * This does NOT write anything to disk.
     */
    private function toCroppedDataUrl(string $binary, int $topCropPx, int $targetHeightPx): ?string
    {
        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false) {
            return null;
        }

        $mime = (string) ($imageInfo['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        // Do not require GD in runtime/tests. If GD is missing, fall back to original bytes.
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return "data:{$mime};base64," . base64_encode($binary);
        }

        $croppedBinary = $this->cropImageBinaryForAi($binary, $mime, $topCropPx, $targetHeightPx);
        if ($croppedBinary === null) {
            $croppedBinary = $binary;
        }

        return "data:{$mime};base64," . base64_encode($croppedBinary);
    }

    /**
     * Crop image to a specific viewport and return encoded bytes.
     *
     * Rules:
     * - If image is too short to remove $topCropPx: return original bytes (don't fail).
     * - If resulting crop equals original (no crop): return original bytes (avoid re-encode).
     * - Otherwise crop from Y=$topCropPx and keep up to $targetHeightPx.
     */
    private function cropImageBinaryForAi(string $binary, string $mime, int $topCropPx, int $targetHeightPx): ?string
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $topCropPx = max(0, $topCropPx);
        $targetHeightPx = max(1, $targetHeightPx);

        // Not enough height to remove the header area - keep original
        if ($height <= $topCropPx) {
            imagedestroy($source);
            return $binary;
        }

        $startY = $topCropPx;
        $cropHeight = min($targetHeightPx, $height - $startY);
        if ($cropHeight <= 0) {
            imagedestroy($source);
            return $binary;
        }

        // No-op crop (keep original bytes)
        if ($startY === 0 && $cropHeight === $height) {
            imagedestroy($source);
            return $binary;
        }

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

        // Crop: copy from (0, startY) to (0, 0)
        imagecopy($cropped, $source, 0, 0, 0, $startY, $width, $cropHeight);
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


