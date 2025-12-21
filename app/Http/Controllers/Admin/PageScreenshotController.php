<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Pages\PageScreenshotDerivativeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PageScreenshotController extends Controller
{
    public function show(Page $page, Request $request, PageScreenshotDerivativeService $screenshots): Response
    {
        $crop = $request->query('crop');
        $cropHeight = is_numeric($crop) ? (int) $crop : null;

        $resolved = $screenshots->resolveForResponse($page, $cropHeight);
        if ($resolved === null) {
            abort(404);
        }

        $etag = (string) $resolved['etag'];
        $lastModified = (int) $resolved['lastModified'];

        // Browser cache for 1 week
        $headers = [
            'Content-Type' => (string) $resolved['mime'],
            'Cache-Control' => 'public, max-age=604800',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', max(0, $lastModified)) . ' GMT',
            'Expires' => gmdate('D, d M Y H:i:s', time() + 604800) . ' GMT',
        ];

        // Conditional requests
        $ifNoneMatch = trim((string) $request->headers->get('If-None-Match', ''));
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return response('', 304, $headers);
        }

        $ifModifiedSince = (string) $request->headers->get('If-Modified-Since', '');
        if ($ifModifiedSince !== '' && $lastModified > 0) {
            $since = strtotime($ifModifiedSince) ?: 0;
            if ($since >= $lastModified) {
                return response('', 304, $headers);
            }
        }

        return response()->file((string) $resolved['absolutePath'], $headers);
    }
}


