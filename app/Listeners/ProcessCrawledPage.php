<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\HtmlDomReady;
use App\Jobs\AnalyzePageWithAiJob;
use App\Jobs\GeneratePageEmbeddingJob;
use App\Jobs\TakePageScreenshotJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener that processes crawled pages when DOM is ready.
 * Dispatches AI analysis, screenshot, and embedding jobs.
 */
class ProcessCrawledPage implements ShouldQueue
{
    /**
     * The name of the queue the job should be sent to.
     */
    public string $queue = 'crawling';

    /**
     * Handle the event.
     */
    public function handle(HtmlDomReady $event): void
    {
        $page = $event->page;

        Log::info('Processing crawled page', [
            'page_id' => $page->id,
            'url' => $page->url,
            'js_rendered' => $event->wasJsRendered,
        ]);

        // Only process once - when raw HTML is first fetched (not JS-rendered)
        // This prevents duplicate processing when renderPageWithJs dispatches HtmlDomReady again
        if ($event->wasJsRendered) {
            Log::debug('Skipping processing - page already processed with raw HTML', [
                'page_id' => $page->id,
            ]);
            return;
        }

        // Dispatch screenshot job (will render with JS and take screenshot)
        TakePageScreenshotJob::dispatch($page, true)
            ->onQueue('screenshots');

        // Dispatch AI analysis job (will run after screenshot is taken)
        AnalyzePageWithAiJob::dispatch($page, true)
            ->onQueue('ai-analysis')
            ->delay(5); // 5 seconds delay to allow screenshot to complete

        // Dispatch embedding generation job (will run after AI analysis)
        GeneratePageEmbeddingJob::dispatch($page)
            ->onQueue('embeddings')
            ->delay(30); // 30 seconds delay to allow AI analysis to complete
    }

    /**
     * Handle a job failure.
     */
    public function failed(HtmlDomReady $event, \Throwable $exception): void
    {
        Log::error('Failed to process crawled page', [
            'page_id' => $event->page->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

