<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\Ollama\OllamaService;
use App\Services\Redis\PageLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Stage 2: Generate recap for pages using Ollama AI.
 *
 * Responsibilities:
 * - Find pages with content but no recap
 * - Generate AI recap using Ollama (ministral-3:3b)
 * - Save recap_content
 *
 * Uses Redis-based locking to prevent duplicate processing.
 */
class GeneratePageRecapCommand extends Command
{
    protected $signature = 'page:recap
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}';

    protected $description = 'Stage 2: Generate recap for pages using Ollama AI';

    private const STAGE = 'recap';

    public function __construct(
        private readonly OllamaService $ollama,
        private readonly PageLockService $lockService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $domainFilter = $this->option('domain');
        $pageId = $this->option('page');

        $this->logSeparator();
        $this->info('🤖 STAGE 2: PAGE RECAP GENERATOR');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $this->info("📡 Ollama: {$this->ollama->getBaseUrl()}");
        $this->info("🧠 Model: {$this->ollama->getModel()}");

        // Fetch and display available models
        $availableModels = $this->ollama->getAvailableModels();
        if (!empty($availableModels)) {
            $this->info("📋 Available models: " . implode(', ', $availableModels));
        } else {
            $this->warn("⚠️  Could not fetch available models from Ollama");
        }

        $this->logSeparator();

        // Check Ollama configuration
        if (!$this->ollama->isConfigured()) {
            $this->error('❌ Ollama is not configured. Check OLLAMA_BASE_URL and OLLAMA_MODEL in .env');
            return self::FAILURE;
        }

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

            $result = $this->processPage($page);
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
            $result = $this->processPage($page);
            $this->lockService->releaseLock($page->id, self::STAGE);

            if ($result) {
                $processed++;
            } else {
                $errors++;
            }

            $this->newLine();
        }

        $this->logSeparator();
        $this->info("✅ STAGE 2 COMPLETED");
        $this->info("   Processed: {$processed}");
        $this->info("   Skipped (locked): {$skipped}");
        if ($errors > 0) {
            $this->warn("   Errors: {$errors}");
        }
        $this->logSeparator();

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Get the next page that needs recap generation.
     */
    private function getNextPage(int $afterId, ?string $domain): ?Page
    {
        $query = Page::query()
            ->where('id', '>', $afterId)
            ->whereNotNull('content_with_tags_purified')
            ->whereNull('recap_content')
            ->orderBy('id');

        if ($domain) {
            $query->whereHas('domain', fn($q) => $q->where('domain', $domain));
        }

        return $query->first();
    }

    /**
     * Process a single page - generate recap with Ollama.
     */
    private function processPage(Page $page): bool
    {
        $startTime = microtime(true);

        $this->info("🔄 Generating recap: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        Log::info('🤖 [Stage2:Recap] Starting recap generation', [
            'page_id' => $page->id,
            'url' => $page->url,
        ]);

        try {
            // Load system prompt
            $systemPrompt = view('ai-prompts.page-recap')->render();

            // Build content for AI
            $parts = [];
            $parts[] = "URL: {$page->url}";

            if (!empty($page->title)) {
                $parts[] = "Title: {$page->title}";
            }

            if (!empty($page->meta_description)) {
                $parts[] = "Description: {$page->meta_description}";
            }

            $parts[] = "\n=== VISIBLE CONTENT ON THE PAGE ===";
            $parts[] = $page->content_with_tags_purified;

            $content = implode("\n", $parts);

            // Truncate if too long
            if (strlen($content) > 50000) {
                $content = substr($content, 0, 50000) . "\n... [truncated]";
            }

            $this->info("   📝 Content length: " . strlen($content) . " chars");

            // Generate recap with Ollama
            $recap = $this->ollama->generateRecap($systemPrompt, $content);

            if ($recap === null || empty($recap)) {
                $this->error("   ❌ Recap generation failed");
                Log::error('🤖 [Stage2:Recap] Recap generation failed', [
                    'page_id' => $page->id,
                ]);
                return false;
            }

            // Save recap
            $page->update(['recap_content' => $recap]);

            $this->info("   ✅ Recap generated (" . strlen($recap) . " chars)");

            $totalTime = round((microtime(true) - $startTime) * 1000);
            $this->info("   ⏱️  Total time: {$totalTime}ms");

            Log::info('🤖 [Stage2:Recap] ✅ Completed', [
                'page_id' => $page->id,
                'recap_length' => strlen($recap),
                'total_time_ms' => $totalTime,
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            Log::error('🤖 [Stage2:Recap] Exception', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    private function logSeparator(string $char = '═'): void
    {
        $this->line(str_repeat($char, 60));
    }
}

