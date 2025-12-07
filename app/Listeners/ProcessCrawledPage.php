<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\HtmlDomReady;
use App\Jobs\AnalyzePageWithAiJob;
use App\Jobs\GeneratePageEmbeddingJob;
use App\Jobs\TakePageScreenshotJob;
use Illuminate\Support\Facades\Log;

/**
 * Listener that processes crawled pages when DOM is ready.
 * Runs synchronously to take screenshots and queue AI analysis.
 */
class ProcessCrawledPage
{
    /**
     * Handle the event.
     */
    public function handle(HtmlDomReady $event): void
    {
        $page = $event->page;

        Log::info('📋 Processing crawled page', [
            'page_id' => $page->id,
            'url' => $page->url,
            'js_rendered' => $event->wasJsRendered,
        ]);

        // Skip if already JS-rendered (to avoid double processing)
        if ($event->wasJsRendered) {
            Log::debug('Skipping - page already JS-rendered', ['page_id' => $page->id]);
            return;
        }

        // Queue screenshot job (requires Puppeteer to be installed)
        $screenshotsEnabled = config('crawler.screenshots_enabled', false);
        if ($screenshotsEnabled) {
            Log::info('📸 Queueing screenshot job...', ['page_id' => $page->id]);
            TakePageScreenshotJob::dispatch($page, true)->onQueue('screenshots');
        } else {
            Log::debug('📸 Screenshots disabled in config', ['page_id' => $page->id]);
        }

        // Queue AI analysis job (without screenshot if disabled)
        AnalyzePageWithAiJob::dispatch($page, $screenshotsEnabled)
            ->onQueue('ai-analysis');

        // Queue embedding generation job
        GeneratePageEmbeddingJob::dispatch($page)
            ->onQueue('embeddings');

        Log::info('✅ Page processing completed', [
            'page_id' => $page->id,
            'screenshot_queued' => $screenshotsEnabled,
            'ai_analysis_queued' => true,
            'embedding_queued' => true,
        ]);
    }
}

