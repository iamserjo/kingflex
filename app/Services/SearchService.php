<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Page;
use App\Models\PageContentTag;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for AI-powered search functionality.
 * Parses user queries into weighted tags and searches pages by tag matching.
 */
class SearchService
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * Search pages using AI-parsed tags.
     *
     * @param string $query User search query
     * @return array{tags: array<string, int>, results: array<int, array>, error: string|null}
     */
    public function search(string $query): array
    {
        // Parse query into weighted tags using AI
        $parsedTags = $this->parseQueryToTags($query);

        if ($parsedTags === null) {
            return [
                'tags' => [],
                'results' => [],
                'error' => 'Failed to parse search query',
            ];
        }

        if (empty($parsedTags)) {
            return [
                'tags' => [],
                'results' => [],
                'error' => null,
            ];
        }

        Log::info('🔍 Search query parsed', [
            'query' => $query,
            'tags' => $parsedTags,
        ]);

        // Search pages by tags
        $results = $this->searchByTags($parsedTags);

        return [
            'tags' => $parsedTags,
            'results' => $results,
            'error' => null,
        ];
    }

    /**
     * Parse user query into weighted tags using AI.
     *
     * @param string $query
     * @return array<string, int>|null
     */
    private function parseQueryToTags(string $query): ?array
    {
        if (!$this->openRouter->isConfigured()) {
            Log::warning('OpenRouter not configured, cannot parse search query');
            return null;
        }

        $systemPrompt = view('ai-prompts.parse-search-query')->render();

        $result = $this->openRouter->chatJson($systemPrompt, $query);

        if ($result === null || !isset($result['tags'])) {
            Log::error('Failed to parse search query', [
                'query' => $query,
            ]);
            return null;
        }

        // Validate and normalize tags
        $tags = [];
        foreach ($result['tags'] as $tag => $weight) {
            $tag = trim((string) $tag);
            if (!empty($tag)) {
                $tags[$tag] = max(1, min(100, (int) $weight));
            }
        }

        return $tags;
    }

    /**
     * Search pages by matching tags with weight proximity ranking.
     *
     * @param array<string, int> $searchTags Tags with weights from user query
     * @return array<int, array>
     */
    private function searchByTags(array $searchTags): array
    {
        $tagNames = array_keys($searchTags);

        // Find all matching tags in database (case-insensitive LIKE match)
        $matchingTags = PageContentTag::query()
            ->where(function ($query) use ($tagNames) {
                foreach ($tagNames as $tag) {
                    $query->orWhere('tag', 'ILIKE', '%' . $tag . '%');
                }
            })
            ->with('page:id,url,title,summary,page_type')
            ->get();

        if ($matchingTags->isEmpty()) {
            return [];
        }

        // Calculate scores for each page
        $pageScores = [];

        foreach ($matchingTags as $dbTag) {
            $pageId = $dbTag->page_id;

            if (!isset($pageScores[$pageId])) {
                $pageScores[$pageId] = [
                    'page' => $dbTag->page,
                    'score' => 0,
                    'matched_tags' => [],
                ];
            }

            // Find which search tag matched this database tag
            foreach ($searchTags as $searchTag => $searchWeight) {
                if (mb_stripos($dbTag->tag, $searchTag) !== false || mb_stripos($searchTag, $dbTag->tag) !== false) {
                    // Calculate weight proximity bonus (closer weights = higher bonus)
                    // Bonus ranges from 0.5 (max difference) to 1.0 (exact match)
                    $weightDiff = abs($searchWeight - $dbTag->weight);
                    $weightProximityBonus = 1 - ($weightDiff / 200); // 0.5 to 1.0 range

                    // Score = search_weight * db_weight * proximity_bonus
                    $tagScore = ($searchWeight / 100) * ($dbTag->weight / 100) * $weightProximityBonus;

                    $pageScores[$pageId]['score'] += $tagScore;
                    $pageScores[$pageId]['matched_tags'][] = [
                        'search_tag' => $searchTag,
                        'db_tag' => $dbTag->tag,
                        'search_weight' => $searchWeight,
                        'db_weight' => $dbTag->weight,
                        'tag_score' => round($tagScore, 4),
                    ];
                }
            }
        }

        // Sort by score descending
        uasort($pageScores, fn($a, $b) => $b['score'] <=> $a['score']);

        // Format results
        $results = [];
        foreach (array_slice($pageScores, 0, 50, true) as $pageId => $data) {
            if ($data['page'] === null || $data['score'] <= 0) {
                continue;
            }

            $results[] = [
                'page_id' => $pageId,
                'url' => $data['page']->url,
                'title' => $data['page']->title,
                'summary' => $data['page']->summary,
                'page_type' => $data['page']->page_type,
                'score' => round($data['score'], 4),
                'matched_tags' => $data['matched_tags'],
            ];
        }

        return $results;
    }
}

