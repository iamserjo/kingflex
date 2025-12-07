<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Page;
use App\Services\Crawler\WebCrawlerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Command to update crawled pages based on their priority.
 * Should be run via cron every hour.
 *
 * Priority is determined by inbound_links_count:
 * - 100+ links: recrawl if > 1 hour old
 * - 10-99 links: recrawl if > 6 hours old
 * - < 10 links: recrawl if > 24 hours old
 * - Never crawled: highest priority
 */
class CrawlUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'crawl:update
                            {--domain= : Specific domain to crawl (optional)}
                            {--limit= : Maximum pages to process (default from config)}
                            {--new-only : Only process new/never-crawled pages}
                            {--force : Force recrawl regardless of last_crawled_at}';

    /**
     * The console command description.
     */
    protected $description = 'Update crawled pages based on priority. Run hourly via cron.';

    /**
     * Execute the console command.
     */
    public function handle(WebCrawlerService $crawlerService): int
    {
        $domainFilter = $this->option('domain');
        $limit = (int) ($this->option('limit') ?: config('crawler.max_pages_per_run'));
        $newOnly = $this->option('new-only');
        $force = $this->option('force');

        $this->info('Starting crawl update...');
        Log::info('Crawl update started', [
            'domain_filter' => $domainFilter,
            'limit' => $limit,
            'new_only' => $newOnly,
            'force' => $force,
        ]);

        // Get active domains
        $domainsQuery = Domain::active();

        if ($domainFilter) {
            $domainsQuery->where('domain', $domainFilter);
        }

        $domains = $domainsQuery->get();

        if ($domains->isEmpty()) {
            $this->warn('No active domains found.');
            return self::SUCCESS;
        }

        $this->info("Found {$domains->count()} active domain(s).");

        $totalProcessed = 0;

        foreach ($domains as $domain) {
            $this->newLine();
            $this->info("Processing domain: {$domain->domain}");

            $processed = $this->processDomain($domain, $crawlerService, $limit - $totalProcessed, $newOnly, $force);
            $totalProcessed += $processed;

            $this->info("  Processed {$processed} pages for {$domain->domain}");

            if ($totalProcessed >= $limit) {
                $this->warn("Reached limit of {$limit} pages.");
                break;
            }
        }

        $this->newLine();
        $this->info("Crawl update completed. Total pages processed: {$totalProcessed}");

        Log::info('Crawl update completed', [
            'total_processed' => $totalProcessed,
        ]);

        return self::SUCCESS;
    }

    /**
     * Process a single domain.
     */
    private function processDomain(
        Domain $domain,
        WebCrawlerService $crawlerService,
        int $limit,
        bool $newOnly,
        bool $force,
    ): int {
        // Check if this is a new domain (no pages yet)
        $pageCount = $domain->pages()->count();

        if ($pageCount === 0) {
            $this->info("  New domain detected, performing full crawl...");
            return $this->crawlNewDomain($domain, $crawlerService, $limit);
        }

        // Get pages that need recrawling
        $pages = $this->getPagesToRecrawl($domain, $limit, $newOnly, $force);

        if ($pages->isEmpty()) {
            $this->info("  No pages need updating.");
            return 0;
        }

        $this->info("  Found {$pages->count()} pages to update.");

        $processed = 0;
        $progressBar = $this->output->createProgressBar($pages->count());
        $progressBar->start();

        foreach ($pages as $page) {
            try {
                $crawlerService->crawlPage($page);
                $processed++;
            } catch (\Exception $e) {
                Log::error('Failed to recrawl page', [
                    'page_id' => $page->id,
                    'url' => $page->url,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return $processed;
    }

    /**
     * Perform full crawl for a new domain.
     */
    private function crawlNewDomain(Domain $domain, WebCrawlerService $crawlerService, int $limit): int
    {
        try {
            $crawlerService->crawlDomain($domain, $limit);

            // Return the number of pages created
            return $domain->pages()->count();
        } catch (\Exception $e) {
            Log::error('Failed to crawl new domain', [
                'domain' => $domain->domain,
                'error' => $e->getMessage(),
            ]);

            $this->error("  Failed to crawl domain: {$e->getMessage()}");
            return 0;
        }
    }

    /**
     * Get pages that need recrawling based on priority.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Page>
     */
    private function getPagesToRecrawl(Domain $domain, int $limit, bool $newOnly, bool $force)
    {
        $query = $domain->pages();

        if ($newOnly) {
            // Only get pages that have never been crawled
            $query->whereNull('last_crawled_at');
        } elseif (!$force) {
            // Use the needsRecrawl scope
            $query->needsRecrawl();
        }

        return $query->limit($limit)->get();
    }
}

