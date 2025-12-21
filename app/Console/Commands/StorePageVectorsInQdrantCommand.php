<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Qdrant\QdrantClient;
use App\Services\Redis\PageLockService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Store product-page vectors (built from product recap fields) into Qdrant and mark pages.qdstored_at.
 *
 * Eligibility:
 * - Page looks like a product page (is_product=true OR page_type=product)
 * - product_summary_specs IS NOT NULL/empty
 * - product_abilities IS NOT NULL/empty
 * - product_predicted_search_text IS NOT NULL/empty
 *
 * Note: we intentionally do NOT require is_product_available/product_type_id here,
 * because `page:recap` can run before `page:product-type-detect`.
 */
final class StorePageVectorsInQdrantCommand extends Command
{
    protected $signature = 'page:qdstore
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}
                            {--force : Re-store even if already stored (qdstored_at is not null)}
                            {--attempts=3 : Max retry attempts per page}
                            {--sleep-ms=0 : Sleep between retries in ms}';

    protected $description = 'Store pages into Qdrant (vector built from product_summary_specs/product_abilities/product_predicted_search_text) and mark qdstored_at';

    private const STAGE = 'qdstore';

    public function __construct(
        private readonly OpenRouterService $openRouter,
        private readonly QdrantClient $qdrant,
        private readonly PageLockService $lockService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // #region agent log
        $runId = (string) (microtime(true));
        $agentLog = static function (string $hypothesisId, string $location, string $message, array $data = []) use ($runId): void {
            try {
                $row = [
                    'sessionId' => 'debug-session',
                    'runId' => $runId,
                    'hypothesisId' => $hypothesisId,
                    'location' => $location,
                    'message' => $message,
                    'data' => $data,
                    'timestamp' => (int) round(microtime(true) * 1000),
                ];
                @file_put_contents('/Users/sergey/Projects/marketking/.cursor/debug.log', json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            } catch (\Throwable) {
                // ignore logging failures
            }
        };
        // #endregion agent log

        $limit = max(1, (int) $this->option('limit'));
        $domainFilter = $this->option('domain');
        $pageId = $this->option('page');
        $force = (bool) $this->option('force');
        $maxAttempts = max(1, (int) $this->option('attempts'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        // #region agent log
        $agentLog('A', 'StorePageVectorsInQdrantCommand.php:handle:env', 'Command options + DB/schema preflight', [
            'limit' => $limit,
            'domain' => $domainFilter,
            'page' => $pageId,
            'force' => $force,
            'attempts' => $maxAttempts,
            'sleep_ms' => $sleepMs,
            'db_default' => (string) config('database.default'),
            'db_connection' => (string) config('database.connections.' . config('database.default') . '.driver'),
            'db_database' => (string) config('database.connections.' . config('database.default') . '.database'),
            'has_qdstored_at' => Schema::hasColumn('pages', 'qdstored_at'),
        ]);
        // #endregion agent log

        $this->logSeparator();
        $this->info('📦 STORE PAGE VECTORS IN QDRANT (from json_attributes)');
        $this->info('⏰ Started at: ' . now()->format('Y-m-d H:i:s'));
        $this->info('🧠 Embedding model: ' . (string) config('openrouter.embedding_model'));
        $this->info('📏 Embedding dimensions: ' . (int) config('openrouter.embedding_dimensions', 0));
        $this->info('🧱 Qdrant host: ' . ($this->qdrant->isConfigured() ? $this->qdrant->host() : '[not configured]'));
        $this->info('📚 Qdrant collection: ' . $this->qdrant->defaultCollection());
        $this->logSeparator();

        if (!Schema::hasColumn('pages', 'qdstored_at')) {
            $this->error('❌ Column pages.qdstored_at is missing. Run migrations inside Docker first.');
            // #region agent log
            $agentLog('A', 'StorePageVectorsInQdrantCommand.php:handle:missing_column', 'Missing pages.qdstored_at, aborting', []);
            // #endregion agent log
            return self::FAILURE;
        }

        if (!$this->openRouter->isConfigured()) {
            $this->error('❌ OpenRouter is not configured. Set OPENROUTER_API_KEY in .env');
            return self::FAILURE;
        }

        if (!$this->qdrant->isConfigured()) {
            $this->error('❌ Qdrant is not configured. Set QDRANT_HOST in .env (default is http://qdrant:6333 inside Docker).');
            return self::FAILURE;
        }

        // Ensure collection exists early (fail-fast)
        try {
            $this->qdrant->ensureCollection();
        } catch (\Throwable $e) {
            $this->error('❌ Qdrant collection init failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            if ($pageId) {
                $page = Page::query()->find($pageId);
                if (!$page) {
                    $this->error("❌ Page not found: {$pageId}");
                    return self::FAILURE;
                }

                if (!$this->lockService->acquireLock($page->id, self::STAGE)) {
                    $this->warn("⚠️  Page {$pageId} is locked by another process");
                    return self::SUCCESS;
                }

                $ok = $this->processPage($page, $force, $maxAttempts, $sleepMs);
                $this->lockService->releaseLock($page->id, self::STAGE);

                return $ok ? self::SUCCESS : self::FAILURE;
            }
        } catch (QueryException $e) {
            // #region agent log
            $agentLog('B', 'StorePageVectorsInQdrantCommand.php:handle:query_exception', 'QueryException', [
                'sql' => $e->getSql(),
                'bindings_count' => is_array($e->getBindings()) ? count($e->getBindings()) : null,
                'message' => $e->getMessage(),
            ]);
            // #endregion agent log
            $this->error('❌ DB error: ' . $e->getMessage());
            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error('❌ ' . $e->getMessage());
            return self::FAILURE;
        }

        $processed = 0;
        $errors = 0;
        $skipped = 0;
        $lastProcessedId = 0;

        while ($processed + $skipped < $limit) {
            $page = $this->getNextPage($lastProcessedId, $domainFilter, $force);

            if (!$page) {
                $this->info('📭 No more pages to process');
                break;
            }

            $lastProcessedId = $page->id;

            if (!$this->lockService->acquireLock($page->id, self::STAGE)) {
                $this->line("   ⏭️  Page {$page->id} is locked, skipping...");
                $skipped++;
                continue;
            }

            $this->logSeparator('─');
            $ok = $this->processPage($page, $force, $maxAttempts, $sleepMs);
            $this->lockService->releaseLock($page->id, self::STAGE);

            if ($ok) {
                $processed++;
            } else {
                $errors++;
            }

            $this->newLine();
        }

        $this->logSeparator();
        $this->info('✅ QDRANT STORE COMPLETED');
        $this->info("   Processed: {$processed}");
        $this->info("   Skipped (locked): {$skipped}");
        if ($errors > 0) {
            $this->warn("   Errors: {$errors}");
        }
        $this->logSeparator();

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function getNextPage(int $afterId, ?string $domain, bool $force): ?Page
    {
        $query = $this->eligibleQuery(domain: $domain)
            ->where('id', '>', $afterId);

        if (!$force) {
            $query->whereNull('qdstored_at');
        }

        return $query->first();
    }

    /**
     * @return Builder<Page>
     */
    private function eligibleQuery(?string $domain = null): Builder
    {
        /** @var Builder<Page> $query */
        $query = Page::query()
            ->where(static function (Builder $q): void {
                $q->where('is_product', true)
                    ->orWhere('page_type', Page::TYPE_PRODUCT);
            })
            ->whereNotNull('product_summary_specs')
            ->whereNotNull('product_abilities')
            ->whereNotNull('product_predicted_search_text')
            ->where('product_summary_specs', '!=', '')
            ->where('product_abilities', '!=', '')
            ->where('product_predicted_search_text', '!=', '')
            ->orderBy('id');

        if ($domain) {
            $query->whereHas('domain', fn (Builder $q) => $q->where('domain', $domain));
        }

        return $query;
    }

    private function processPage(Page $page, bool $force, int $maxAttempts, int $sleepMs): bool
    {
        $startedAt = microtime(true);

        $this->info("🔄 Storing in Qdrant: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        if (!$force && $page->qdstored_at !== null) {
            $this->line('   ⏭️  Already stored, skipping (use --force to re-run)');
            return true;
        }

        if (empty($page->product_summary_specs) || empty($page->product_abilities) || empty($page->product_predicted_search_text)) {
            $this->warn('   ⚠️  Missing product_summary_specs/product_abilities/product_predicted_search_text; skipping');
            return false;
        }

        $text = $this->buildTextForEmbedding($page);

        Log::info('📦 [QdStore] Starting', [
            'page_id' => $page->id,
            'url' => $page->url,
            'text_length' => strlen($text),
        ]);

        $collection = $this->qdrant->defaultCollection();
        $targetDims = (int) config('openrouter.embedding_dimensions', 0);
        $targetDims = max(1, $targetDims);

        $attempt = 0;
        while (true) {
            $attempt++;
            if ($attempt > $maxAttempts) {
                $this->error("   ❌ Exceeded max attempts ({$maxAttempts})");
                Log::error('📦 [QdStore] Exceeded max attempts', [
                    'page_id' => $page->id,
                    'attempts' => $attempt - 1,
                ]);
                return false;
            }

            $this->line("   🤖 Attempt #{$attempt}...");

            try {
                $embedding = $this->openRouter->createEmbedding($text);
                if ($embedding === null || $embedding === []) {
                    $this->warn('   ⚠️  Embedding generation failed, retrying...');
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                if (count($embedding) > $targetDims) {
                    $embedding = array_slice($embedding, 0, $targetDims);
                }

                if (count($embedding) !== $targetDims) {
                    $this->error("   ❌ Embedding dimensions mismatch: got " . count($embedding) . ", expected {$targetDims}");
                    Log::error('📦 [QdStore] Embedding dimensions mismatch', [
                        'page_id' => $page->id,
                        'got' => count($embedding),
                        'expected' => $targetDims,
                        'model' => config('openrouter.embedding_model'),
                    ]);
                    return false;
                }

                $payload = [
                    'page_id' => $page->id,
                    'domain_id' => $page->domain_id,
                    'url' => $page->url,
                    'title' => $page->title,
                    'product_type_id' => $page->product_type_id,
                    'sku' => $page->sku,
                    'product_code' => $page->product_code,
                    'product_model_number' => $page->product_model_number,
                    'product_summary_specs' => $page->product_summary_specs,
                    'product_abilities' => $page->product_abilities,
                    'product_predicted_search_text' => $page->product_predicted_search_text,
                ];

                $this->qdrant->upsertPoints($collection, [
                    [
                        'id' => $page->id,
                        'vector' => $embedding,
                        'payload' => $payload,
                    ],
                ], wait: true);

                $page->update(['qdstored_at' => now()]);

                $this->info('   ✅ Stored in Qdrant');
                $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));

                Log::info('📦 [QdStore] ✅ Completed', [
                    'page_id' => $page->id,
                    'collection' => $collection,
                    'dimensions' => $targetDims,
                    'attempts' => $attempt,
                    'took_seconds' => round(microtime(true) - $startedAt, 3),
                ]);

                return true;
            } catch (\Throwable $e) {
                $this->warn('   ⚠️  Error: ' . $e->getMessage());
                Log::warning('📦 [QdStore] Error, retrying', [
                    'page_id' => $page->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                $this->sleepIfNeeded($sleepMs);
                continue;
            }
        }
    }

    private function buildTextForEmbedding(Page $page): string
    {
        $parts = [];
        $parts[] = (string) $page->product_summary_specs;
        $parts[] = (string) $page->product_abilities;
        $parts[] = (string) $page->product_predicted_search_text;

        return trim(implode("\n\n", array_filter($parts, static fn ($p) => trim((string) $p) !== '')));
    }

    private function sleepIfNeeded(int $sleepMs): void
    {
        if ($sleepMs <= 0) {
            return;
        }

        usleep($sleepMs * 1000);
    }

    private function formatSeconds(float $seconds): string
    {
        return number_format(max(0.0, $seconds), 2) . 's';
    }

    private function logSeparator(string $char = '═'): void
    {
        $this->line(str_repeat($char, 60));
    }
}



