<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\HtmlDomReady;
use App\Jobs\AnalyzePageWithAiJob;
use App\Jobs\ExtractContentTagsJob;
use App\Jobs\ExtractSearchTagsJob;
use App\Jobs\GeneratePageEmbeddingJob;
use App\Jobs\PageRecapJob;
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

        // 1. Create page recap (one sentence summary) and generate embedding
        $this->runPageRecap($page);

        // TODO: Temporarily disabled - uncomment when needed
        // // 2. AI Page Analysis (type, summary, structured data)
        // $this->runPageAnalysis($page);
        //
        // // 3. Extract Content Tags (what the page is about)
        // $this->runContentTagsExtraction($page);
        //
        // // 4. Extract Search Tags (how users might search)
        // $this->runSearchTagsExtraction($page);

        Log::info('✅ Page processing completed', [
            'page_id' => $page->id,
        ]);
    }

    /**
     * Create page recap and generate embedding.
     */
    private function runPageRecap($page): void
    {
        try {
            Log::info('📝 [1/1] Creating page recap...', ['page_id' => $page->id]);
            PageRecapJob::dispatchSync($page);
            Log::info('✅ Page recap completed', ['page_id' => $page->id]);
        } catch (\Exception $e) {
            Log::error('❌ Page recap failed', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run AI page analysis.
     */
    private function runPageAnalysis($page): void
    {
        try {
            Log::info('🤖 Starting page analysis...', ['page_id' => $page->id]);
            AnalyzePageWithAiJob::dispatchSync($page, false);
            Log::info('✅ Page analysis completed', ['page_id' => $page->id]);
        } catch (\Exception $e) {
            Log::error('❌ Page analysis failed', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run content tags extraction.
     */
    private function runContentTagsExtraction($page): void
    {
        try {
            Log::info('🏷️ Extracting content tags...', ['page_id' => $page->id]);
            ExtractContentTagsJob::dispatchSync($page);
            Log::info('✅ Content tags extracted', ['page_id' => $page->id]);
        } catch (\Exception $e) {
            Log::error('❌ Content tags extraction failed', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run search tags extraction.
     */
    private function runSearchTagsExtraction($page): void
    {
        try {
            Log::info('🔍 Extracting search tags...', ['page_id' => $page->id]);
            ExtractSearchTagsJob::dispatchSync($page);
            Log::info('✅ Search tags extracted', ['page_id' => $page->id]);
        } catch (\Exception $e) {
            Log::error('❌ Search tags extraction failed', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
