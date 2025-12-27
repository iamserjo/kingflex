<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Page;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Support\Facades\Log;

/**
 * Service for AI-powered semantic search functionality.
 * Uses vector embeddings to find semantically similar pages.
 */
class SearchService
{
    /**
     * Minimum similarity threshold (0-1). Pages below this won't be returned.
     */
    private const MIN_SIMILARITY_THRESHOLD = 0.3;

    /**
     * Maximum results to return.
     */
    private const MAX_RESULTS = 50;

    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * Search pages using vector similarity.
     *
     * @param string $query User search query
     * @return array{results: array<int, array>, error: string|null, query_time_ms: int}
     */
    public function search(string $query): array
    {
        $startTime = microtime(true);

        Log::info('🔍 [Search] Starting vector search', [
            'query' => $query,
            'query_length' => strlen($query),
        ]);

        // Check if OpenRouter is configured
        if (!$this->openRouter->isConfigured()) {
            Log::warning('🔍 [Search] OpenRouter not configured');
            return [
                'results' => [],
                'error' => 'Search service is not configured',
                'query_time_ms' => $this->getElapsedMs($startTime),
            ];
        }

        // Create embedding for user query
        Log::debug('🔍 [Search] Creating embedding for query...');
        $embeddingStartTime = microtime(true);

        $queryEmbedding = $this->openRouter->createEmbedding($query);

        $embeddingTime = $this->getElapsedMs($embeddingStartTime);

        if ($queryEmbedding === null) {
            Log::error('🔍 [Search] Failed to create query embedding', [
                'query' => $query,
                'embedding_time_ms' => $embeddingTime,
            ]);
            return [
                'results' => [],
                'error' => 'Failed to process search query',
                'query_time_ms' => $this->getElapsedMs($startTime),
            ];
        }

        Log::debug('🔍 [Search] Query embedding created', [
            'dimensions' => count($queryEmbedding),
            'embedding_time_ms' => $embeddingTime,
        ]);

        // Search pages by vector similarity
        Log::debug('🔍 [Search] Searching pages by vector similarity...');
        $searchStartTime = microtime(true);

        $results = $this->searchByEmbedding($queryEmbedding);

        $searchTime = $this->getElapsedMs($searchStartTime);
        $totalTime = $this->getElapsedMs($startTime);

        Log::info('🔍 [Search] ✅ Search completed', [
            'query' => $query,
            'results_count' => count($results),
            'embedding_time_ms' => $embeddingTime,
            'db_search_time_ms' => $searchTime,
            'total_time_ms' => $totalTime,
        ]);

        return [
            'results' => $results,
            'error' => null,
            'query_time_ms' => $totalTime,
        ];
    }

    /**
     * Search pages by embedding vector similarity.
     *
     * @param array<float> $queryEmbedding Query embedding vector
     * @return array<int, array>
     */
    private function searchByEmbedding(array $queryEmbedding): array
    {
        // Trim embedding to configured dimensions to match pgvector column
        $targetDims = (int) config('openrouter.embedding_dimensions', count($queryEmbedding));
        if (count($queryEmbedding) > $targetDims) {
            $queryEmbedding = array_slice($queryEmbedding, 0, $targetDims);
        }

        // Convert embedding to pgvector format
        $embeddingString = '[' . implode(',', $queryEmbedding) . ']';

        // Query pages with vector similarity using cosine distance
        // <=> operator returns cosine distance (0 = identical, 2 = opposite)
        $pages = Page::query()
            ->whereNotNull('embedding')
            ->selectRaw('id, url, title, product_summary as summary, recap_content, page_type, embedding <=> ? as distance', [$embeddingString])
            ->orderBy('distance')
            ->limit(self::MAX_RESULTS * 2) // Get more to filter by threshold
            ->get();

        Log::debug('🔍 [Search] Raw results from database', [
            'total_pages_with_embedding' => Page::whereNotNull('embedding')->count(),
            'results_before_filter' => $pages->count(),
        ]);

        // Convert to array and calculate similarity scores
        $results = [];

        foreach ($pages as $page) {
            // Convert distance to similarity score (0-1)
            // Cosine distance ranges 0-2, so similarity = 1 - (distance / 2)
            $similarity = 1 - ($page->distance / 2);

            // Skip results below threshold
            if ($similarity < self::MIN_SIMILARITY_THRESHOLD) {
                continue;
            }

            $results[] = [
                'page_id' => $page->id,
                'url' => $page->url,
                'title' => $page->title,
                'summary' => $page->summary,
                'recap_content' => $page->recap_content,
                'page_type' => $page->page_type?->value,
                'score' => round($similarity, 4),
                'distance' => round($page->distance, 6),
            ];

            // Stop after MAX_RESULTS
            if (count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        Log::debug('🔍 [Search] Results after filtering', [
            'results_after_filter' => count($results),
            'min_threshold' => self::MIN_SIMILARITY_THRESHOLD,
            'top_score' => $results[0]['score'] ?? null,
            'bottom_score' => end($results)['score'] ?? null,
        ]);

        return $results;
    }

    /**
     * Get elapsed time in milliseconds.
     */
    private function getElapsedMs(float $startTime): int
    {
        return (int) round((microtime(true) - $startTime) * 1000);
    }
}
