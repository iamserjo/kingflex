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

        $url = $screenshots->resolveRedirectUrl($page, $cropHeight);
        if ($url === null) {
            abort(404);
        }

        return redirect()->away($url);
    }
}


