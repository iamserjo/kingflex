<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Page;
use App\Services\Html\HtmlSanitizerService;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Playwright\ContentExtractorService;
use App\Services\Storage\PageAssetsStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to create a one-sentence recap of a page using AI.
 * The recap is used for embedding generation and semantic search.
 * 
 * Uses Playwright to render page with JavaScript and extract semantic content.
 * Falls back to raw HTML sanitization if Playwright fails.
 */
class PageRecapJob implements ShouldQueue
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
        public bool $usePlaywright = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        OpenRouterService $openRouter,
        ContentExtractorService $playwrightExtractor,
        HtmlSanitizerService $htmlSanitizer,
        PageAssetsStorageService $assets,
    ): void {
        $jobStartTime = microtime(true);

        Log::info('📝 [PageRecapJob] ══════════════════════════════════════════', [
            'page_id' => $this->page->id,
        ]);
        Log::info('📝 [PageRecapJob] Starting job', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'usePlaywright' => $this->usePlaywright,
            'attempt' => $this->attempts(),
            'maxTries' => $this->tries,
        ]);

        if (!$openRouter->isConfigured()) {
            Log::warning('📝 [PageRecapJob] ⚠️ OpenRouter not configured, skipping', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        Log::debug('📝 [PageRecapJob] OpenRouter configured, proceeding', [
            'page_id' => $this->page->id,
        ]);

        // Get content - prefer Playwright for JS-rendered content
        Log::info('📝 [PageRecapJob] Step 1: Extracting content...', [
            'page_id' => $this->page->id,
            'method' => $this->usePlaywright ? 'Playwright' : 'RawHTML',
        ]);

        $content = $this->getContent($playwrightExtractor, $htmlSanitizer, $assets);

        if (empty($content)) {
            Log::warning('📝 [PageRecapJob] ⚠️ No content available, aborting', [
                'page_id' => $this->page->id,
                'url' => $this->page->url,
            ]);
            return;
        }

        Log::info('📝 [PageRecapJob] Step 1 complete: Content extracted', [
            'page_id' => $this->page->id,
            'contentLength' => strlen($content),
        ]);

        // Get system prompt
        Log::debug('📝 [PageRecapJob] Loading AI prompt template', [
            'page_id' => $this->page->id,
            'template' => 'ai-prompts.page-recap',
        ]);

        $systemPrompt = view('ai-prompts.page-recap')->render();

        Log::debug('📝 [PageRecapJob] AI prompt loaded', [
            'page_id' => $this->page->id,
            'promptLength' => strlen($systemPrompt),
        ]);

        // Request recap from AI (plain text, not JSON)
        Log::info('📝 [PageRecapJob] Step 2: Requesting AI recap...', [
            'page_id' => $this->page->id,
            'contentLength' => strlen($content),
        ]);

        $aiStartTime = microtime(true);
        $response = $openRouter->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $content],
        ]);
        $aiTime = round((microtime(true) - $aiStartTime) * 1000);

        if ($response === null || empty($response['content'])) {
            Log::error('📝 [PageRecapJob] ❌ AI recap generation failed', [
                'page_id' => $this->page->id,
                'response' => $response,
                'aiTimeMs' => $aiTime,
            ]);
            return;
        }

        Log::info('📝 [PageRecapJob] Step 2 complete: AI response received', [
            'page_id' => $this->page->id,
            'aiTimeMs' => $aiTime,
            'responseLength' => strlen($response['content']),
            'model' => $response['model'] ?? 'unknown',
            'usage' => $response['usage'] ?? null,
        ]);

        // Clean up the recap (remove quotes, extra whitespace)
        $recap = trim($response['content']);
        $recap = trim($recap, '"\'');

        Log::debug('📝 [PageRecapJob] Recap cleaned', [
            'page_id' => $this->page->id,
            'recapLength' => strlen($recap),
            'recapPreview' => substr($recap, 0, 200),
        ]);

        // Save recap to page
        Log::info('📝 [PageRecapJob] Step 3: Saving recap to database...', [
            'page_id' => $this->page->id,
        ]);

        $this->page->update(['recap_content' => $recap]);

        Log::info('📝 [PageRecapJob] ✅ Step 3 complete: Recap saved', [
            'page_id' => $this->page->id,
            'recap' => $recap,
        ]);

        // Generate embedding for the recap
        Log::info('📝 [PageRecapJob] Step 4: Generating embedding...', [
            'page_id' => $this->page->id,
        ]);

        $this->generateRecapEmbedding($openRouter, $recap);

        $totalTime = round((microtime(true) - $jobStartTime) * 1000);

        Log::info('📝 [PageRecapJob] ══════════════════════════════════════════', [
            'page_id' => $this->page->id,
        ]);
        Log::info('📝 [PageRecapJob] ✅ Job completed successfully', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'totalTimeMs' => $totalTime,
            'recapLength' => strlen($recap),
        ]);
    }

    /**
     * Get page content for AI analysis.
     * Tries Playwright first, falls back to raw HTML sanitization.
     * Saves extracted content to page model.
     */
    private function getContent(
        ContentExtractorService $playwrightExtractor,
        HtmlSanitizerService $htmlSanitizer,
        PageAssetsStorageService $assets,
    ): string {
        $maxLength = 50000;

        // Try Playwright extraction first (renders JS, gets semantic structure)
        if ($this->usePlaywright && !empty($this->page->url)) {
            Log::info('📝 [PageRecapJob] Using Playwright extraction', [
                'page_id' => $this->page->id,
                'url' => $this->page->url,
            ]);

            $extractStartTime = microtime(true);
            $result = $playwrightExtractor->extract($this->page->url);
            $extractTime = round((microtime(true) - $extractStartTime) * 1000);

            Log::debug('📝 [PageRecapJob] Playwright extraction result', [
                'page_id' => $this->page->id,
                'success' => $result['success'],
                'hasContent' => !empty($result['content']),
                'contentLength' => strlen($result['content'] ?? ''),
                'title' => $result['title'] ?? null,
                'hasDescription' => !empty($result['description']),
                'error' => $result['error'] ?? null,
                'loadTimeMs' => $result['loadTimeMs'] ?? null,
                'extractTimeMs' => $extractTime,
            ]);

            if ($result['success'] && !empty($result['content'])) {
                // Save purified content with tags to the page model
                Log::debug('📝 [PageRecapJob] Saving content_with_tags_purified...', [
                    'page_id' => $this->page->id,
                    'contentLength' => strlen($result['content']),
                ]);

                $purifiedUrl = $assets->storePurifiedContent($this->page, (string) $result['content']);
                $this->page->update([
                    'content_with_tags_purified' => $purifiedUrl,
                ]);

                Log::info('📝 [PageRecapJob] ✅ Playwright content saved to page', [
                    'page_id' => $this->page->id,
                    'contentLength' => strlen($result['content']),
                    'extractTimeMs' => $extractTime,
                ]);

                // Build formatted content for AI
                $formattedContent = $this->formatContentForAi($result, $maxLength);

                Log::debug('📝 [PageRecapJob] Content formatted for AI', [
                    'page_id' => $this->page->id,
                    'formattedLength' => strlen($formattedContent),
                ]);

                return $formattedContent;
            }

            Log::warning('📝 [PageRecapJob] ⚠️ Playwright extraction failed', [
                'page_id' => $this->page->id,
                'error' => $result['error'] ?? 'No content returned',
                'extractTimeMs' => $extractTime,
            ]);

            Log::info('📝 [PageRecapJob] Falling back to raw HTML sanitization...', [
                'page_id' => $this->page->id,
            ]);
        } else {
            Log::debug('📝 [PageRecapJob] Skipping Playwright', [
                'page_id' => $this->page->id,
                'usePlaywright' => $this->usePlaywright,
                'hasUrl' => !empty($this->page->url),
            ]);
        }

        // Fallback to raw HTML sanitization
        if (!empty($this->page->raw_html)) {
            $rawHtmlUrl = (string) $this->page->raw_html;
            $rawHtml = $assets->getTextFromUrl($rawHtmlUrl);
            $rawHtmlLength = strlen($rawHtml);

            Log::info('📝 [PageRecapJob] Using raw HTML sanitization fallback', [
                'page_id' => $this->page->id,
                'rawHtmlLength' => $rawHtmlLength,
            ]);

            $sanitizedContent = $htmlSanitizer->getForAi($rawHtml, $this->page->url, $maxLength);

            Log::debug('📝 [PageRecapJob] Raw HTML sanitized', [
                'page_id' => $this->page->id,
                'originalLength' => $rawHtmlLength,
                'sanitizedLength' => strlen($sanitizedContent),
            ]);

            return $sanitizedContent;
        }

        Log::warning('📝 [PageRecapJob] ⚠️ No content source available', [
            'page_id' => $this->page->id,
            'hasRawHtml' => !empty($this->page->raw_html),
            'hasUrl' => !empty($this->page->url),
        ]);

        return '';
    }

    /**
     * Format extracted content for AI consumption.
     *
     * @param array{content: ?string, title: ?string, description: ?string} $result
     * @param int $maxLength
     * @return string
     */
    private function formatContentForAi(array $result, int $maxLength): string
    {
        $parts = [];

        // Add URL
        $parts[] = "URL: {$this->page->url}";

        // Add metadata
        if (!empty($result['title'])) {
            $parts[] = "Title: {$result['title']}";
        }

        if (!empty($result['description'])) {
            $parts[] = "Description: {$result['description']}";
        }

        // Add content
        $parts[] = "\n=== RENDERED CONTENT ===";

        $content = $result['content'] ?? '';
        $originalLength = strlen($content);

        // Truncate if needed
        if ($maxLength > 0 && strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength) . "\n... [truncated]";

            Log::debug('📝 [PageRecapJob] Content truncated for AI', [
                'page_id' => $this->page->id,
                'originalLength' => $originalLength,
                'truncatedTo' => $maxLength,
            ]);
        }

        $parts[] = $content;

        return implode("\n", $parts);
    }

    /**
     * Generate and save embedding for the recap.
     */
    private function generateRecapEmbedding(OpenRouterService $openRouter, string $recap): void
    {
        Log::info('📝 [PageRecapJob] 🔢 Generating recap embedding...', [
            'page_id' => $this->page->id,
            'recapLength' => strlen($recap),
        ]);

        $embeddingStartTime = microtime(true);
        $embedding = $openRouter->createEmbedding($recap);
        $embeddingTime = round((microtime(true) - $embeddingStartTime) * 1000);

        if ($embedding === null) {
            Log::error('📝 [PageRecapJob] ❌ Embedding generation failed', [
                'page_id' => $this->page->id,
                'embeddingTimeMs' => $embeddingTime,
            ]);
            return;
        }

        Log::debug('📝 [PageRecapJob] Embedding generated', [
            'page_id' => $this->page->id,
            'dimensions' => count($embedding),
            'embeddingTimeMs' => $embeddingTime,
        ]);

        // Save embedding to database
        $embeddingString = '[' . implode(',', $embedding) . ']';

        Log::debug('📝 [PageRecapJob] Saving embedding to database...', [
            'page_id' => $this->page->id,
            'embeddingStringLength' => strlen($embeddingString),
        ]);

        DB::statement(
            'UPDATE pages SET embedding = ? WHERE id = ?',
            [$embeddingString, $this->page->id]
        );

        Log::info('📝 [PageRecapJob] ✅ Embedding saved', [
            'page_id' => $this->page->id,
            'dimensions' => count($embedding),
            'embeddingTimeMs' => $embeddingTime,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('📝 [PageRecapJob] ❌ JOB FAILED', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'error' => $exception->getMessage(),
            'exceptionClass' => get_class($exception),
            'attempt' => $this->attempts(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
