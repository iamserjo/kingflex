<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Redis\PageLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stage 3: Generate embeddings for pages using OpenRouter.
 * 
 * Responsibilities:
 * - Find pages with recap but no embedding
 * - Generate embedding using OpenRouter API
 * - Save embedding vector
 * 
 * Uses Redis-based locking to prevent duplicate processing.
 */
class GeneratePageEmbeddingCommand extends Command
{
    protected $signature = 'page:embed
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}';

    protected $description = 'Stage 3: Generate embeddings for pages using OpenRouter';

    private const STAGE = 'embed';

    public function __construct(
        private readonly OpenRouterService $openRouter,
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
        $this->info('🔢 STAGE 3: PAGE EMBEDDING GENERATOR');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $this->logSeparator();

        // Check OpenRouter configuration
        if (!$this->openRouter->isConfigured()) {
            $this->error('❌ OpenRouter is not configured. Set OPENROUTER_API_KEY in .env');
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
        $this->info("✅ STAGE 3 COMPLETED");
        $this->info("   Processed: {$processed}");
        $this->info("   Skipped (locked): {$skipped}");
        if ($errors > 0) {
            $this->warn("   Errors: {$errors}");
        }
        $this->logSeparator();

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Get the next page that needs embedding generation.
     */
    private function getNextPage(int $afterId, ?string $domain): ?Page
    {
        $query = Page::query()
            ->where('id', '>', $afterId)
            ->whereNotNull('recap_content')
            ->whereNull('embedding')
            ->orderBy('id');

        if ($domain) {
            $query->whereHas('domain', fn($q) => $q->where('domain', $domain));
        }

        return $query->first();
    }

    /**
     * Process a single page - generate embedding with OpenRouter.
     */
    private function processPage(Page $page): bool
    {
        $startTime = microtime(true);

        $this->info("🔄 Generating embedding: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        Log::info('🔢 [Stage3:Embed] Starting embedding generation', [
            'page_id' => $page->id,
            'url' => $page->url,
        ]);

        try {
            $recap = $page->recap_content;

            if (empty($recap)) {
                $this->error("   ❌ No recap content available");
                Log::error('🔢 [Stage3:Embed] No recap content', [
                    'page_id' => $page->id,
                ]);
                return false;
            }

            $this->info("   📝 Recap length: " . strlen($recap) . " chars");

            // Generate embedding
            $embedding = $this->openRouter->createEmbedding($recap);

            if ($embedding === null || empty($embedding)) {
                $this->error("   ❌ Embedding generation failed");
                Log::error('🔢 [Stage3:Embed] Embedding generation failed', [
                    'page_id' => $page->id,
                ]);
                return false;
            }

            // Trim embedding to configured dimensions to match pgvector column
            $targetDims = (int) config('openrouter.embedding_dimensions', count($embedding));
            if (count($embedding) > $targetDims) {
                $embedding = array_slice($embedding, 0, $targetDims);
            }

            // Save embedding using raw SQL for pgvector
            $embeddingString = '[' . implode(',', $embedding) . ']';
            DB::statement('UPDATE pages SET embedding = ? WHERE id = ?', [$embeddingString, $page->id]);

            $this->info("   ✅ Embedding generated (" . count($embedding) . " dimensions)");

            $totalTime = round((microtime(true) - $startTime) * 1000);
            $this->info("   ⏱️  Total time: {$totalTime}ms");

            Log::info('🔢 [Stage3:Embed] ✅ Completed', [
                'page_id' => $page->id,
                'dimensions' => count($embedding),
                'total_time_ms' => $totalTime,
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            Log::error('🔢 [Stage3:Embed] Exception', [
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

