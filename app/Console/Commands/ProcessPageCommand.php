<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Playwright\ContentExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Command to process pages one by one using Playwright headless browser.
 * 
 * Flow:
 * 1. Get next unprocessed page from DB (with locking to prevent duplicates)
 * 2. Render page with Playwright and extract semantic content
 * 3. Generate recap using AI
 * 4. Generate embedding for search
 * 5. Mark page as processed and release lock
 * 
 * Uses FOR UPDATE SKIP LOCKED to prevent parallel processes from
 * processing the same page.
 */
class ProcessPageCommand extends Command
{
    /**
     * Lock timeout in minutes. Stale locks older than this are considered abandoned.
     */
    private const LOCK_TIMEOUT_MINUTES = 30;

    protected $signature = 'page:process
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}
                            {--force : Re-process even if already processed}
                            {--no-embedding : Skip embedding generation}
                            {--timeout=30000 : Playwright timeout in ms}';

    protected $description = 'Process pages using Playwright browser - extract content, generate recap and embedding';

    public function __construct(
        private readonly ContentExtractorService $contentExtractor,
        private readonly OpenRouterService $openRouter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $domainFilter = $this->option('domain');
        $pageId = $this->option('page');
        $force = $this->option('force');
        $skipEmbedding = $this->option('no-embedding');
        $timeout = (int) $this->option('timeout');

        $this->logSeparator();
        $this->info('🌐 PAGE PROCESSOR - Playwright Edition');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $this->logSeparator();

        // Check OpenRouter configuration
        if (!$this->openRouter->isConfigured()) {
            $this->error('❌ OpenRouter is not configured. Set OPENROUTER_API_KEY in .env');
            return self::FAILURE;
        }

        // Get pages to process (with locking)
        $pages = $this->getPagesToProcess($limit, $domainFilter, $pageId, $force);

        if ($pages->isEmpty()) {
            $this->warn('⚠️  No pages to process.');
            return self::SUCCESS;
        }

        $this->info("📋 Locked {$pages->count()} page(s) for processing");
        $this->newLine();

        $processed = 0;
        $errors = 0;

        foreach ($pages as $page) {
            $this->logSeparator('─');
            $result = $this->processPage($page, $timeout, $skipEmbedding);

            if ($result) {
                $processed++;
            } else {
                $errors++;
            }

            $this->newLine();
        }

        $this->logSeparator();
        $this->info("✅ COMPLETED");
        $this->info("   Processed: {$processed}");
        if ($errors > 0) {
            $this->warn("   Errors: {$errors}");
        }
        $this->logSeparator();

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Get pages that need processing with database-level locking.
     * Uses FOR UPDATE SKIP LOCKED to prevent parallel processes from
     * selecting the same pages.
     * 
     * The transaction is kept SHORT - only select IDs and mark as processing,
     * then commit immediately so other processes can continue.
     * 
     * @return Collection<int, Page>
     */
    private function getPagesToProcess(int $limit, ?string $domain, ?string $pageId, bool $force): Collection
    {
        // Specific page by ID (no locking needed for single page)
        if ($pageId) {
            $page = Page::find($pageId);
            if ($page) {
                // Mark as processing
                $page->update(['processing_started_at' => now()]);
                return collect([$page]);
            }
            return collect();
        }

        // SHORT transaction: select IDs and mark as processing, then commit
        // This releases the FOR UPDATE lock immediately after marking
        $pageIds = DB::transaction(function () use ($limit, $domain, $force) {
            $lockTimeout = now()->subMinutes(self::LOCK_TIMEOUT_MINUTES);

            // Build base query for pages that need processing
            $query = Page::query();

            // Domain filter
            if ($domain) {
                $query->whereHas('domain', fn($q) => $q->where('domain', $domain));
            }

            // Only pages not currently being processed (or with stale locks)
            $query->where(function ($q) use ($lockTimeout) {
                $q->whereNull('processing_started_at')
                  ->orWhere('processing_started_at', '<', $lockTimeout);
            });

            // Only unprocessed unless --force
            if (!$force) {
                $query->where(function ($q) {
                    $q->whereNull('last_crawled_at')
                      ->orWhereNull('content_with_tags_purified')
                      ->orWhereNull('embedding');
                });
            }

            // Order by priority (never processed first, then by age)
            $query->orderByRaw('CASE WHEN last_crawled_at IS NULL THEN 0 ELSE 1 END')
                  ->orderBy('last_crawled_at', 'asc');

            // Debug: count available before locking
            $availableCount = (clone $query)->count();
            Log::debug('🌐 [ProcessPage] Query stats before lock', [
                'available_count' => $availableCount,
                'limit' => $limit,
                'force' => $force,
            ]);

            // FOR UPDATE SKIP LOCKED - skip rows already locked by other processes
            // Only select IDs to minimize lock time
            $pageIds = $query->limit($limit)
                            ->lock('FOR UPDATE SKIP LOCKED')
                            ->pluck('id')
                            ->toArray();

            if (empty($pageIds)) {
                Log::warning('🌐 [ProcessPage] No pages selected after SKIP LOCKED', [
                    'available_before_lock' => $availableCount,
                    'pages_with_processing_started' => Page::whereNotNull('processing_started_at')
                        ->where('processing_started_at', '>=', $lockTimeout)
                        ->count(),
                ]);
                return [];
            }

            // Mark selected pages as being processed IMMEDIATELY
            // This is our "soft lock" that persists after transaction commits
            Page::whereIn('id', $pageIds)->update(['processing_started_at' => now()]);

            Log::info('🌐 [ProcessPage] Pages locked for processing', [
                'count' => count($pageIds),
                'page_ids' => $pageIds,
            ]);

            return $pageIds;
        });
        // Transaction commits here - FOR UPDATE lock is released!

        if (empty($pageIds)) {
            return collect();
        }

        // Fetch full page models OUTSIDE transaction (no lock held)
        return Page::whereIn('id', $pageIds)->get();
    }

    /**
     * Process a single page.
     * Releases lock on completion (success or failure).
     */
    private function processPage(Page $page, int $timeout, bool $skipEmbedding): bool
    {
        $startTime = microtime(true);

        $this->info("🔄 Processing: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        Log::info('🌐 [ProcessPage] Starting page processing', [
            'page_id' => $page->id,
            'url' => $page->url,
        ]);

        try {
            // Step 1: Extract content with Playwright
            $this->info("   [1/3] Extracting content with Playwright...");
            
            $extractResult = $this->contentExtractor->extract($page->url, $timeout);

            if (!$extractResult['success'] || empty($extractResult['content'])) {
                $this->error("   ❌ Failed to extract content: " . ($extractResult['error'] ?? 'Unknown error'));
                Log::error('🌐 [ProcessPage] Content extraction failed', [
                    'page_id' => $page->id,
                    'error' => $extractResult['error'],
                ]);
                $this->releaseLock($page);
                return false;
            }

            $this->info("   ✅ Content extracted ({$extractResult['loadTimeMs']}ms, " . strlen($extractResult['content']) . " chars)");

            // Save extracted content
            $page->update([
                'content_with_tags_purified' => $extractResult['content'],
                'title' => $extractResult['title'] ?? $page->title,
                'last_crawled_at' => now(),
            ]);

            Log::info('🌐 [ProcessPage] Content saved', [
                'page_id' => $page->id,
                'content_length' => strlen($extractResult['content']),
            ]);

            // Step 2: Generate recap with AI
            $this->info("   [2/3] Generating AI recap...");

            $recap = $this->generateRecap($page, $extractResult);

            if ($recap) {
                $page->update(['recap_content' => $recap]);
                $this->info("   ✅ Recap generated (" . strlen($recap) . " chars)");
            } else {
                $this->warn("   ⚠️  Recap generation failed");
            }

            // Step 3: Generate embedding
            if (!$skipEmbedding && $recap) {
                $this->info("   [3/3] Generating embedding...");

                $embedding = $this->openRouter->createEmbedding($recap);

                if ($embedding) {
                    $embeddingString = '[' . implode(',', $embedding) . ']';
                    DB::statement('UPDATE pages SET embedding = ? WHERE id = ?', [$embeddingString, $page->id]);
                    $this->info("   ✅ Embedding generated (" . count($embedding) . " dimensions)");

                    Log::info('🌐 [ProcessPage] Embedding saved', [
                        'page_id' => $page->id,
                        'dimensions' => count($embedding),
                    ]);
                } else {
                    $this->warn("   ⚠️  Embedding generation failed");
                }
            } elseif ($skipEmbedding) {
                $this->info("   [3/3] Skipping embedding (--no-embedding)");
            }

            $totalTime = round((microtime(true) - $startTime) * 1000);
            $this->info("   ⏱️  Total time: {$totalTime}ms");

            Log::info('🌐 [ProcessPage] ✅ Page processing completed', [
                'page_id' => $page->id,
                'total_time_ms' => $totalTime,
            ]);

            // Release lock on success
            $this->releaseLock($page);

            return true;

        } catch (\Throwable $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            Log::error('🌐 [ProcessPage] Exception during processing', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Release lock on failure
            $this->releaseLock($page);

            return false;
        }
    }

    /**
     * Release processing lock on a page.
     */
    private function releaseLock(Page $page): void
    {
        $page->update(['processing_started_at' => null]);

        Log::debug('🌐 [ProcessPage] Lock released', [
            'page_id' => $page->id,
        ]);
    }

    /**
     * Generate recap for a page.
     */
    private function generateRecap(Page $page, array $extractResult): ?string
    {
        $systemPrompt = view('ai-prompts.page-recap')->render();

        // Build content for AI
        $parts = [];
        $parts[] = "URL: {$page->url}";

        if (!empty($extractResult['title'])) {
            $parts[] = "Title: {$extractResult['title']}";
        }

        if (!empty($extractResult['description'])) {
            $parts[] = "Description: {$extractResult['description']}";
        }

        $parts[] = "\n=== CONTENT ===";
        $parts[] = $extractResult['content'];

        $content = implode("\n", $parts);

        // Truncate if too long
        if (strlen($content) > 50000) {
            $content = substr($content, 0, 50000) . "\n... [truncated]";
        }

        $response = $this->openRouter->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $content],
        ]);

        if ($response === null || empty($response['content'])) {
            Log::error('🌐 [ProcessPage] Recap generation failed', [
                'page_id' => $page->id,
            ]);
            return null;
        }

        $recap = trim($response['content']);
        $recap = trim($recap, '"\'');

        Log::info('🌐 [ProcessPage] Recap generated', [
            'page_id' => $page->id,
            'recap_length' => strlen($recap),
        ]);

        return $recap;
    }

    private function logSeparator(string $char = '═'): void
    {
        $this->line(str_repeat($char, 60));
    }
}
