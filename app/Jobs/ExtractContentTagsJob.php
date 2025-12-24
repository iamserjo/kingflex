<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Page;
use App\Models\PageContentTag;
use App\Services\Html\HtmlSanitizerService;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Storage\PageAssetsStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to extract content tags from a page using AI.
 * These tags describe what the page is actually about.
 */
class ExtractContentTagsJob implements ShouldQueue
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
    public function handle(OpenRouterService $openRouter, HtmlSanitizerService $sanitizer, PageAssetsStorageService $assets): void
    {
        if (!$openRouter->isConfigured()) {
            Log::warning('OpenRouter not configured, skipping content tags extraction', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        Log::info('🏷️ Extracting content tags', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
        ]);

        $systemPrompt = view('ai-prompts.extract-content-tags')->render();
        $rawHtmlUrl = (string) ($this->page->raw_html ?? '');
        $rawHtml = $rawHtmlUrl !== '' ? $assets->getTextFromUrl($rawHtmlUrl) : '';
        $content = $sanitizer->getForAi($rawHtml, $this->page->url, 30000);

        if (empty($content)) {
            Log::warning('No content available for tag extraction', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        $result = $openRouter->chatJson($systemPrompt, $content);

        if ($result === null || !isset($result['tags'])) {
            Log::error('❌ Content tags extraction failed', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        $this->saveTags($result['tags']);

        Log::info('✅ Content tags extracted', [
            'page_id' => $this->page->id,
            'tags_count' => count($result['tags']),
        ]);
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
            PageContentTag::where('page_id', $this->page->id)->delete();

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
                PageContentTag::insert($records);
            }
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Content tags extraction job failed', [
            'page_id' => $this->page->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

