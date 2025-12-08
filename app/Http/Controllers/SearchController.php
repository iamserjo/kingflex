<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Controller for handling search functionality.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $searchService,
    ) {}

    /**
     * Display the home page with search interface.
     */
    public function home(): View
    {
        return view('home');
    }

    /**
     * Handle search request.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $query = $request->input('query');
        $results = $this->searchService->search($query);

        return response()->json($results);
    }
}

