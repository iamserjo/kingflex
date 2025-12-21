<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pages\PageProductTypeCandidateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PageProductTypeController extends Controller
{
    public function index(Request $request, PageProductTypeCandidateService $candidates): View
    {
        $domain = $request->query('domain');
        $domain = is_string($domain) && trim($domain) !== '' ? trim($domain) : null;

        $pending = $candidates->pending(limit: 5, domain: $domain, force: false);
        $pending->loadMissing(['productType']);

        return view('admin.pages.product_type', [
            'pending' => $pending,
            'domain' => $domain,
        ]);
    }
}


