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
        $startTime = now();
        $domainFilter = $this->option('domain');
        $limit = (int) ($this->option('limit') ?: config('crawler.max_pages_per_run'));
        $newOnly = $this->option('new-only');
        $force = $this->option('force');

        $this->logSeparator();
        $this->info('🕷️  CRAWLER UPDATE STARTED');
        $this->info("⏰ Time: {$startTime->format('Y-m-d H:i:s')}");
        $this->logSeparator();

        Log::info('=== CRAWL UPDATE STARTED ===', [
            'timestamp' => $startTime->toISOString(),
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
            $this->warn('⚠️  No active domains found.');
            Log::warning('No active domains found to crawl');
            return self::SUCCESS;
        }

        $this->info("✅ Found {$domains->count()} active domain(s)");
        Log::info("Found {$domains->count()} active domains", [
            'domains' => $domains->pluck('domain')->toArray(),
        ]);

        $totalProcessed = 0;
        $totalErrors = 0;

        foreach ($domains as $domain) {
            $this->newLine();
            $this->logSeparator('─');
            $this->info("🌐 Processing domain: {$domain->domain}");

            $result = $this->processDomain($domain, $crawlerService, $limit - $totalProcessed, $newOnly, $force);
            $totalProcessed += $result['processed'];
            $totalErrors += $result['errors'];

            $this->info("  ✓ Processed: {$result['processed']} pages");
            if ($result['errors'] > 0) {
                $this->warn("  ⚠ Errors: {$result['errors']}");
            }
            $this->info("  📊 Queue size: {$result['queue_size']} pages pending");

            if ($totalProcessed >= $limit) {
                $this->warn("⚠️  Reached limit of {$limit} pages.");
                Log::info("Reached processing limit", ['limit' => $limit]);
                break;
            }
        }

        $duration = $startTime->diffInSeconds(now());

        $this->newLine();
        $this->logSeparator();
        $this->info("✅ CRAWL UPDATE COMPLETED");
        $this->info("📈 Total pages processed: {$totalProcessed}");
        if ($totalErrors > 0) {
            $this->warn("⚠️  Total errors: {$totalErrors}");
        }
        $this->info("⏱️  Duration: {$duration}s");
        $this->logSeparator();

        Log::info('=== CRAWL UPDATE COMPLETED ===', [
            'total_processed' => $totalProcessed,
            'total_errors' => $totalErrors,
            'duration_seconds' => $duration,
            'pages_per_second' => $totalProcessed > 0 ? round($totalProcessed / max($duration, 1), 2) : 0,
        ]);

        return self::SUCCESS;
    }

    /**
     * Print a separator line.
     */
    private function logSeparator(string $char = '='): void
    {
        $this->line(str_repeat($char, 60));
    }

    /**
     * Process a single domain.
     *
     * @return array{processed: int, errors: int, queue_size: int}
     */
    private function processDomain(
        Domain $domain,
        WebCrawlerService $crawlerService,
        int $limit,
        bool $newOnly,
        bool $force,
    ): array {
        $domainStartTime = now();

        // Check if this is a new domain (no pages yet)
        $pageCount = $domain->pages()->count();

        if ($pageCount === 0) {
            $this->info("  🆕 New domain detected, crawling homepage...");
            Log::info("New domain detected", ['domain' => $domain->domain]);

            $result = $this->crawlNewDomain($domain, $crawlerService, $limit);

            Log::info("Homepage crawled for new domain", [
                'domain' => $domain->domain,
                'pages_discovered' => $result['processed'],
                'duration_seconds' => $domainStartTime->diffInSeconds(now()),
            ]);

            return $result;
        }

        // Get pages that need recrawling
        $pages = $this->getPagesToRecrawl($domain, $limit, $newOnly, $force);

        if ($pages->isEmpty()) {
            $this->info("  ℹ️  No pages need updating.");
            Log::debug("No pages need updating for domain", ['domain' => $domain->domain]);

            return [
                'processed' => 0,
                'errors' => 0,
                'queue_size' => $domain->pages()->whereNull('last_crawled_at')->count(),
            ];
        }

        $this->info("  📋 Found {$pages->count()} pages to update");
        Log::info("Pages queued for crawling", [
            'domain' => $domain->domain,
            'count' => $pages->count(),
        ]);

        $processed = 0;
        $errors = 0;
        $progressBar = $this->output->createProgressBar($pages->count());
        $progressBar->start();

        foreach ($pages as $page) {
            try {
                Log::debug("Crawling page", [
                    'page_id' => $page->id,
                    'url' => $page->url,
                    'inbound_links' => $page->inbound_links_count,
                    'last_crawled' => $page->last_crawled_at?->format('Y-m-d H:i:s'),
                ]);

                $crawlerService->crawlPage($page);
                $processed++;

            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed to recrawl page', [
                    'page_id' => $page->id,
                    'url' => $page->url,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $queueSize = $domain->pages()->whereNull('last_crawled_at')->count();

        Log::info("Domain processing completed", [
            'domain' => $domain->domain,
            'processed' => $processed,
            'errors' => $errors,
            'queue_size' => $queueSize,
            'duration_seconds' => $domainStartTime->diffInSeconds(now()),
        ]);

        return [
            'processed' => $processed,
            'errors' => $errors,
            'queue_size' => $queueSize,
        ];
    }

    /**
     * Perform homepage crawl for a new domain to discover initial links.
     *
     * @return array{processed: int, errors: int, queue_size: int}
     */
    private function crawlNewDomain(Domain $domain, WebCrawlerService $crawlerService, int $limit): array
    {
        try {
            $crawlerService->crawlDomain($domain, $limit);

            $pagesCount = $domain->pages()->count();
            $queueSize = $domain->pages()->whereNull('last_crawled_at')->count();

            return [
                'processed' => 1, // Homepage was processed
                'errors' => 0,
                'queue_size' => $queueSize,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to crawl new domain homepage', [
                'domain' => $domain->domain,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("  ❌ Failed to crawl domain: {$e->getMessage()}");

            return [
                'processed' => 0,
                'errors' => 1,
                'queue_size' => 0,
            ];
        }
    }

    /**
     * Get pages that need recrawling based on priority formula.
     * Priority = effective_age (time - popularity_bonus) DESC
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Page>
     */
    private function getPagesToRecrawl(Domain $domain, int $limit, bool $newOnly, bool $force)
    {
        $query = $domain->pages();

        if ($newOnly) {
            // Only get pages that have never been crawled
            $query->whereNull('last_crawled_at')
                ->orderBy('created_at', 'asc'); // Oldest first
        } elseif (!$force) {
            // Use the needsRecrawl scope (includes priority ordering)
            $query->needsRecrawl();
        } else {
            // Force mode: recrawl all, ordered by priority
            $hoursPerLink = config('crawler.recrawl_priority.hours_per_link');
            $query->orderByRaw('
                CASE
                    WHEN last_crawled_at IS NULL THEN 0
                    ELSE EXTRACT(EPOCH FROM (NOW() - last_crawled_at)) / 3600 - (inbound_links_count * ?)
                END DESC
            ', [$hoursPerLink]);
        }

        return $query->limit($limit)->get();
    }
}

