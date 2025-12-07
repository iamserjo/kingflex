<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Page;
use App\Models\PageSearchTag;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to extract search tags from a page using AI.
 * These tags represent how users might search for this page.
 */
class ExtractSearchTagsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Page $page,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(OpenRouterService $openRouter): void
    {
        if (!$openRouter->isConfigured()) {
            Log::warning('OpenRouter not configured, skipping search tags extraction', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        Log::info('🔍 Extracting search tags', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
        ]);

        $systemPrompt = view('ai-prompts.extract-search-tags')->render();
        $content = $this->prepareContent();

        if (empty($content)) {
            Log::warning('No content available for search tag extraction', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        $result = $openRouter->chatJson($systemPrompt, $content);

        if ($result === null || !isset($result['tags'])) {
            Log::error('❌ Search tags extraction failed', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        $this->saveTags($result['tags']);

        Log::info('✅ Search tags extracted', [
            'page_id' => $this->page->id,
            'tags_count' => count($result['tags']),
        ]);
    }

    /**
     * Prepare content for AI analysis.
     */
    private function prepareContent(): string
    {
        $parts = [];

        // Add URL
        $parts[] = "URL: {$this->page->url}";

        // Add title if available
        if (!empty($this->page->title)) {
            $parts[] = "Title: {$this->page->title}";
        }

        // Add summary if available
        if (!empty($this->page->summary)) {
            $parts[] = "Summary: {$this->page->summary}";
        }

        // Add page type
        if (!empty($this->page->page_type)) {
            $parts[] = "Page Type: {$this->page->page_type}";
        }

        // Add raw HTML (limited)
        $html = $this->page->raw_html;
        if (!empty($html)) {
            $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is', '', $html);
            $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/is', '', $html);

            $maxLength = 20000;
            if (strlen($html) > $maxLength) {
                $html = substr($html, 0, $maxLength) . '... [truncated]';
            }

            $parts[] = "\nHTML:\n{$html}";
        }

        return implode("\n", $parts);
    }

    /**
     * Save extracted tags to database.
     *
     * @param array<string, int> $tags
     */
    private function saveTags(array $tags): void
    {
        DB::transaction(function () use ($tags) {
            // Delete existing tags for this page
            PageSearchTag::where('page_id', $this->page->id)->delete();

            // Insert new tags
            $records = [];
            $now = now();

            foreach ($tags as $tag => $weight) {
                // Validate weight
                $weight = max(1, min(100, (int) $weight));

                $records[] = [
                    'page_id' => $this->page->id,
                    'tag' => (string) $tag,
                    'weight' => $weight,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($records)) {
                PageSearchTag::insert($records);
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Search tags extraction job failed', [
            'page_id' => $this->page->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

