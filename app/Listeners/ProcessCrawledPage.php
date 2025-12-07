<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\HtmlDomReady;
use App\Jobs\AnalyzePageWithAiJob;
use App\Jobs\GeneratePageEmbeddingJob;
use Illuminate\Support\Facades\Log;

/**
 * Listener that processes crawled pages when DOM is ready.
 * Runs AI analysis synchronously to analyze raw HTML content.
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
            'html_length' => strlen($event->html),
        ]);

        // Skip if already JS-rendered (to avoid double processing)
        if ($event->wasJsRendered) {
            Log::debug('Skipping - page already JS-rendered', ['page_id' => $page->id]);
            return;
        }

        // Run AI analysis synchronously (send raw HTML to AI)
        try {
            Log::info('🤖 Starting AI analysis...', [
                'page_id' => $page->id,
                'url' => $page->url,
            ]);

            AnalyzePageWithAiJob::dispatchSync($page, false); // false = no screenshot

            Log::info('✅ AI analysis completed', ['page_id' => $page->id]);
        } catch (\Exception $e) {
            Log::error('❌ AI analysis failed', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Queue embedding generation (can be async)
        GeneratePageEmbeddingJob::dispatch($page)
            ->onQueue('embeddings');

        Log::info('✅ Page processing completed', [
            'page_id' => $page->id,
            'ai_analyzed' => true,
            'embedding_queued' => true,
        ]);
    }
}

