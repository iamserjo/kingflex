<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Page;
use App\Services\Crawler\WebCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to take a screenshot of a page using Puppeteer.
 */
class TakePageScreenshotJob implements ShouldQueue
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
    public int $backoff = 30;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Page $page,
        public bool $renderWithJs = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WebCrawlerService $crawlerService): void
    {
        Log::info('Taking screenshot for page', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'render_with_js' => $this->renderWithJs,
        ]);

        try {
            if ($this->renderWithJs) {
                // Render page with JavaScript and take screenshot
                $crawlerService->renderPageWithJs($this->page, true);
            } else {
                // Just take a screenshot without re-rendering
                $crawlerService->takeScreenshot($this->page);
            }

            Log::info('Screenshot taken successfully', [
                'page_id' => $this->page->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to take screenshot', [
                'page_id' => $this->page->id,
                'url' => $this->page->url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Screenshot job failed', [
            'page_id' => $this->page->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

