<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Page;
use App\Services\Playwright\ContentExtractorService;
use App\Services\Redis\PageLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stage 1: Extract content from pages using Playwright.
 *
 * Responsibilities:
 * - Download page with Playwright
 * - Save raw HTML content
 * - Save purified content with semantic tags
 * - Save meta title, description, keywords
 * - Extract URLs and add new pages to process
 *
 * Uses Redis-based locking to prevent duplicate processing.
 */
class ExtractPageContentCommand extends Command
{
    protected $signature = 'page:extract
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}
                            {--timeout=30000 : Playwright timeout in ms}';

    protected $description = 'Stage 1: Extract content from pages using Playwright browser';

    private const STAGE = 'extract';

    public function __construct(
        private readonly ContentExtractorService $contentExtractor,
        private readonly PageLockService $lockService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $domainFilter = $this->option('domain');
        $pageId = $this->option('page');
        $timeout = (int) $this->option('timeout');

        $this->logSeparator();
        $this->info('🌐 STAGE 1: PAGE CONTENT EXTRACTOR');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $this->logSeparator();

        $processed = 0;
        $errors = 0;
        $skipped = 0;

        // Process specific page by ID
        if ($pageId) {
            $page = Page::find($pageId);
            if (!$page) {
                $this->error("❌ Page not found: {$pageId}");
                return self::FAILURE;
            }

            if (!$this->lockService->acquireLock($page->id, self::STAGE)) {
                $this->warn("⚠️  Page {$pageId} is locked by another process");
                return self::SUCCESS;
            }

            $result = $this->processPage($page, $timeout);
            $this->lockService->releaseLock($page->id, self::STAGE);

            return $result ? self::SUCCESS : self::FAILURE;
        }

        // Process multiple pages
        $lastProcessedId = 0;

        while ($processed + $skipped < $limit) {
            $page = $this->getNextPage($lastProcessedId, $domainFilter);

            if (!$page) {
                $this->info("📭 No more pages to process");
                break;
            }

            $lastProcessedId = $page->id;

            // Try to acquire lock
            if (!$this->lockService->acquireLock($page->id, self::STAGE)) {
                $this->line("   ⏭️  Page {$page->id} is locked, skipping...");
                $skipped++;
                continue;
            }

            $this->logSeparator('─');
            $result = $this->processPage($page, $timeout);
            $this->lockService->releaseLock($page->id, self::STAGE);

            if ($result) {
                $processed++;
            } else {
                $errors++;
            }

            $this->newLine();
        }

        $this->logSeparator();
        $this->info("✅ STAGE 1 COMPLETED");
        $this->info("   Processed: {$processed}");
        $this->info("   Skipped (locked): {$skipped}");
        if ($errors > 0) {
            $this->warn("   Errors: {$errors}");
        }
        $this->logSeparator();

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Get the next page that needs content extraction.
     */
    private function getNextPage(int $afterId, ?string $domain): ?Page
    {
        $query = Page::query()
            ->where('id', '>', $afterId)
            ->whereNull('recap_content')
            ->where(function ($q) {
                $q->whereNull('raw_html')
                    ->orWhereNull('content_with_tags_purified');
            })
            ->orderBy('id');

        if ($domain) {
            $query->whereHas('domain', fn($q) => $q->where('domain', $domain));
        }

        return $query->first();
    }

    /**
     * Process a single page - extract content with Playwright.
     */
    private function processPage(Page $page, int $timeout): bool
    {
        $startTime = microtime(true);

        $this->info("🔄 Extracting: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        Log::info('🌐 [Stage1:Extract] Starting content extraction', [
            'page_id' => $page->id,
            'url' => $page->url,
        ]);

        try {
            // Extract content with Playwright
            $extractResult = $this->contentExtractor->extract($page->url, $timeout);

            if (!$extractResult['success']) {
                $this->error("   ❌ Failed to extract: " . ($extractResult['error'] ?? 'Unknown error'));
                Log::error('🌐 [Stage1:Extract] Content extraction failed', [
                    'page_id' => $page->id,
                    'error' => $extractResult['error'],
                ]);
                return false;
            }

            $this->info("   ✅ Content extracted ({$extractResult['loadTimeMs']}ms)");

            // Update page with extracted content
            $updateData = [
                'raw_html' => $extractResult['rawHtml'],
                'content_with_tags_purified' => $extractResult['content'],
                'title' => $extractResult['title'] ?? $page->title,
                'meta_description' => $extractResult['description'],
                'last_crawled_at' => now(),
            ];

            // Parse and save keywords if available
            if (!empty($extractResult['keywords'])) {
                $keywords = array_map('trim', explode(',', $extractResult['keywords']));
                $keywords = array_filter($keywords);
                $updateData['keywords'] = $keywords;
            }

            $page->update($updateData);

            $this->info("   📄 Raw HTML: " . strlen($extractResult['rawHtml'] ?? '') . " chars");
            $this->info("   📝 Purified: " . strlen($extractResult['content'] ?? '') . " chars");

            Log::info('🌐 [Stage1:Extract] Content saved', [
                'page_id' => $page->id,
                'raw_html_length' => strlen($extractResult['rawHtml'] ?? ''),
                'content_length' => strlen($extractResult['content'] ?? ''),
                'has_title' => !empty($extractResult['title']),
                'has_description' => !empty($extractResult['description']),
                'has_keywords' => !empty($extractResult['keywords']),
            ]);

            // Extract and save new URLs
            $newUrls = $this->saveExtractedUrls($page, $extractResult['extractedUrls'] ?? []);
            if ($newUrls > 0) {
                $this->info("   🔗 Added {$newUrls} new URLs to process");
            }

            $totalTime = round((microtime(true) - $startTime) * 1000);
            $this->info("   ⏱️  Total time: {$totalTime}ms");

            Log::info('🌐 [Stage1:Extract] ✅ Completed', [
                'page_id' => $page->id,
                'total_time_ms' => $totalTime,
                'new_urls_added' => $newUrls,
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            Log::error('🌐 [Stage1:Extract] Exception', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Save extracted URLs as new pages to process.
     * Only adds URLs from the same domain that don't already exist.
     *
     * @return int Number of new URLs added
     */
    private function saveExtractedUrls(Page $sourcePage, array $urls): int
    {
        if (empty($urls)) {
            return 0;
        }

        $domain = $sourcePage->domain;
        $domainHost = parse_url("https://{$domain->domain}", PHP_URL_HOST);
        $added = 0;

        foreach ($urls as $url) {
            try {
                $parsedUrl = parse_url($url);
                $urlHost = $parsedUrl['host'] ?? null;

                // Skip external URLs
                if ($urlHost !== $domainHost) {
                    continue;
                }

                // Normalize URL (remove fragment, trailing slash)
                $normalizedUrl = $parsedUrl['scheme'] . '://' . $urlHost;
                if (!empty($parsedUrl['port'])) {
                    $normalizedUrl .= ':' . $parsedUrl['port'];
                }
                $normalizedUrl .= $parsedUrl['path'] ?? '/';
                if (!empty($parsedUrl['query'])) {
                    $normalizedUrl .= '?' . $parsedUrl['query'];
                }
                $normalizedUrl = rtrim($normalizedUrl, '/');

                // Check if URL already exists
                $urlHash = hash('sha256', $normalizedUrl);
                $exists = Page::where('domain_id', $domain->id)
                    ->where('url_hash', $urlHash)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Add new page
                Page::create([
                    'domain_id' => $domain->id,
                    'url' => $normalizedUrl,
                    'url_hash' => $urlHash,
                    'depth' => $sourcePage->depth + 1,
                ]);

                $added++;

                Log::debug('🌐 [Stage1:Extract] New URL added', [
                    'source_page_id' => $sourcePage->id,
                    'new_url' => $normalizedUrl,
                ]);

            } catch (\Throwable $e) {
                // Skip invalid URLs silently
                continue;
            }
        }

        return $added;
    }

    private function logSeparator(string $char = '═'): void
    {
        $this->line(str_repeat($char, 60));
    }
}

