<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Pages\PageAttributesCandidateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PageAttributesController extends Controller
{
    public function index(Request $request, PageAttributesCandidateService $candidates): View
    {
        $domain = $request->query('domain');
        $domain = is_string($domain) && trim($domain) !== '' ? trim($domain) : null;

        $pending = $candidates->pending(limit: 5, domain: $domain);
        $processed = $candidates->processed(perPage: 50, domain: $domain);

        // Eager-load productType relation if present (added in follow-up task).
        $pending->loadMissing(['productType']);
        $processed->getCollection()->loadMissing(['productType']);

        return view('admin.pages.attributes', [
            'pending' => $pending,
            'processed' => $processed,
            'domain' => $domain,
        ]);
    }
}


