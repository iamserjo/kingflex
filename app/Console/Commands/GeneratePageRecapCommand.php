<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\Json\JsonParserService;
use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use App\Services\Pages\PageCandidateFinderService;
use App\Services\Pages\PageScreenshotDataUrlService;
use App\Services\Redis\PageLockService;
use App\Services\Storage\PageAssetsStorageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Stage 2: Generate product fields for pages using LM Studio OpenAPI.
 *
 * Responsibilities:
 * - Find product pages with extracted content that are missing product fields
 * - Generate product_summary/product specs/abilities/predicted search queries
 *
 * Uses Redis-based locking to prevent duplicate processing.
 */
class GeneratePageRecapCommand extends Command
{
    protected $signature = 'page:recap
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}
                            {--attempts=3 : Max retry attempts per page when JSON is invalid/truncated}
                            {--sleep-ms=250 : Sleep between retries in ms}';

    protected $description = 'Stage 2: Generate product fields for pages using LM Studio OpenAPI';

    private const STAGE = 'recap';

    public function __construct(
        private readonly LmStudioOpenApiService $openAi,
        private readonly PageLockService $lockService,
        private readonly PageCandidateFinderService $candidates,
        private readonly JsonParserService $jsonParser,
        private readonly PageScreenshotDataUrlService $screenshotDataUrl,
        private readonly PageAssetsStorageService $assets,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $domainFilter = $this->option('domain');
        $pageId = $this->option('page');
        $maxAttempts = max(1, (int) $this->option('attempts'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        $this->logSeparator();
        $this->info('🤖 STAGE 2: PRODUCT FIELDS GENERATOR');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $recapModel = $this->getRecapModelOverride();
        $this->info("📡 LM Studio: {$this->openAi->getBaseUrl()}");
        $this->info("🧠 Model: " . ($recapModel ?? $this->openAi->getModel()));

        $this->logSeparator();

        // Check LM Studio configuration
        if (!$this->openAi->isConfigured()) {
            $this->error('❌ LM Studio OpenAPI is not configured. Check LM_STUDIO_OPENAPI_BASE_URL and LM_STUDIO_OPENAPI_MODEL in .env');
            return self::FAILURE;
        }

        $processed = 0;
        $errors = 0;
        $skipped = 0;
        $attempted = 0;

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

            $result = $this->processPage($page, $maxAttempts, $sleepMs);
            $this->lockService->releaseLock($page->id, self::STAGE);

            return $result ? self::SUCCESS : self::FAILURE;
        }

        // Process multiple pages
        $lastProcessedId = 0;

        while ($attempted < $limit) {
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
            $result = $this->processPage($page, $maxAttempts, $sleepMs);
            $this->lockService->releaseLock($page->id, self::STAGE);
            $attempted++;

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
     * Process a single page - generate product fields with Ollama.
     */
    private function processPage(Page $page, int $maxAttempts, int $sleepMs): bool
    {
        $startTime = microtime(true);

        $this->info("🔄 Generating product fields: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        if (!$this->isProductPage($page)) {
            $this->warn('   ⏭️  Not a product page; skipping');
            Log::info('🤖 [Stage2:ProductFields] Skipped (not product)', [
                'page_id' => $page->id,
                'url' => $page->url,
                'page_type' => $page->page_type,
                'is_product' => $page->is_product,
            ]);
            return true;
        }

        if (empty($page->screenshot_path)) {
            $this->warn('   ⚠️  screenshot_path is empty; skipping (vision required)');
            Log::warning('🤖 [Stage2:ProductFields] Skipped (missing screenshot_path)', [
                'page_id' => $page->id,
                'url' => $page->url,
            ]);
            return false;
        }

        Log::info('🤖 [Stage2:ProductFields] Starting generation', [
            'page_id' => $page->id,
            'url' => $page->url,
        ]);

        try {
            // Load system prompt
            $systemPrompt = view('ai-prompts.product-recap-fields')->render();

            // Build minimal text context for AI (screenshot is the primary signal)
            $parts = [];
            $parts[] = "URL: {$page->url}";

            if (!empty($page->title)) {
                $parts[] = "Title: {$page->title}";
            }

            if (!empty($page->meta_description)) {
                $parts[] = "Description: {$page->meta_description}";
            }

            if (!empty($page->content_with_tags_purified)) {
                $purifiedUrl = (string) $page->content_with_tags_purified;
                try {
                    $purified = $this->assets->getTextFromUrl($purifiedUrl);
                } catch (\Throwable) {
                    $purified = '';
                }

                $preview = $this->buildContentPreview($purified, 2500);
                if ($preview !== '') {
                    $parts[] = "Content preview: {$preview}";
                }
            }

            $content = implode("\n", $parts);

            // Truncate if too long
            if (strlen($content) > 50000) {
                $content = substr($content, 0, 50000) . "\n... [truncated]";
            }

            $this->info("   📝 Content length: " . strlen($content) . " chars");

            $imageDataUrl = $this->screenshotDataUrl->forPage($page);
            if ($imageDataUrl === null) {
                $this->warn('   ⚠️  Screenshot file does not exist; skipping');
                Log::warning('🤖 [Stage2:ProductFields] Screenshot missing on disk', [
                    'page_id' => $page->id,
                    'screenshot_path' => $page->screenshot_path,
                ]);
                return false;
            }

            $options = [
                // Some LM Studio servers only support text response_format.
                'response_format' => ['type' => 'text'],
            ];
            $recapModel = $this->getRecapModelOverride();
            if ($recapModel !== null) {
                $options['model'] = $recapModel;
            }

            $attempt = 0;
            while (true) {
                $attempt++;
                if ($attempt > $maxAttempts) {
                    $this->error("   ❌ Exceeded max attempts ({$maxAttempts})");
                    Log::error('🤖 [Stage2:ProductFields] Exceeded max attempts', [
                        'page_id' => $page->id,
                        'attempts' => $attempt - 1,
                    ]);
                    return false;
                }

                $this->line("   🤖 Attempt #{$attempt}...");

                $response = $this->openAi->chatWithImage(
                    systemPrompt: (string) $systemPrompt,
                    userText: $content,
                    imageDataUrl: $imageDataUrl,
                    options: $options,
                );

                $raw = (string) ($response['content'] ?? '');
                if ($raw === '') {
                    $this->warn("   ⚠️  Empty AI response, retrying...");
                    Log::warning('🤖 [Stage2:ProductFields] Empty response', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'error' => $response['error'] ?? null,
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                $parsed = $this->jsonParser->parseWithKeys($raw, [
                    'product_summary',
                    'product_summary_specs',
                    'product_abilities',
                    'product_predicted_search_text',
                ]);

                if ($parsed === null) {
                    $this->warn('   ⚠️  Invalid JSON response, retrying...');
                    Log::warning('🤖 [Stage2:ProductFields] Invalid JSON response', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'response_preview' => substr($raw, 0, 500),
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                $productSummary = $this->normalizeNullableText($parsed['product_summary'] ?? null);
                $productSpecs = $this->normalizeNullableText($parsed['product_summary_specs'] ?? null);
                $productAbilities = $this->normalizeNullableText($parsed['product_abilities'] ?? null);
                $predictedSearch = $this->normalizePredictedSearchText($parsed['product_predicted_search_text'] ?? null);

                if ($productSummary === null || $productSpecs === null || $productAbilities === null || $predictedSearch === null) {
                    $this->warn('   ⚠️  Parsed JSON has empty required fields, retrying...');
                    Log::warning('🤖 [Stage2:ProductFields] Empty fields after normalization', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'has_product_summary' => $productSummary !== null,
                        'has_product_specs' => $productSpecs !== null,
                        'has_product_abilities' => $productAbilities !== null,
                        'has_predicted_search' => $predictedSearch !== null,
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                // Save product fields (do NOT touch recap_content)
                $page->update([
                    'product_summary' => $productSummary,
                    'product_summary_specs' => $productSpecs,
                    'product_abilities' => $productAbilities,
                    'product_predicted_search_text' => $predictedSearch,
                ]);

                $this->info("   ✅ Product fields generated");

                $totalTime = round((microtime(true) - $startTime) * 1000);
                $this->info("   ⏱️  Total time: {$totalTime}ms");

                Log::info('🤖 [Stage2:ProductFields] ✅ Completed', [
                    'page_id' => $page->id,
                    'total_time_ms' => $totalTime,
                    'attempts' => $attempt,
                ]);

                return true;
            }

        } catch (\Throwable $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            Log::error('🤖 [Stage2:ProductFields] Exception', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    private function sleepIfNeeded(int $sleepMs): void
    {
        if ($sleepMs <= 0) {
            return;
        }

        usleep($sleepMs * 1000);
    }

    private function getNextPage(int $afterId, ?string $domain): ?Page
    {
        return $this->candidates->nextCandidate(
            afterId: $afterId,
            domain: $domain,
            configure: function (Builder $query): void {
                $query
                    ->whereNotNull('content_with_tags_purified')
                    ->whereNotNull('screenshot_path')
                    ->whereNotNull('last_crawled_at')
                    ->where(static function (Builder $q): void {
                        $q->where('is_product', true)
                            ->orWhere('page_type', Page::TYPE_PRODUCT);
                    })
                    ->where(static function (Builder $q): void {
                        $q->whereNull('product_summary')
                            ->orWhereNull('product_summary_specs')
                            ->orWhereNull('product_abilities')
                            ->orWhereNull('product_predicted_search_text')
                            ->orWhere('product_summary', '')
                            ->orWhere('product_summary_specs', '')
                            ->orWhere('product_abilities', '')
                            ->orWhere('product_predicted_search_text', '');
                    })
                    ->orderBy('last_crawled_at', 'desc');
            },
        );
    }

    private function isProductPage(Page $page): bool
    {
        return $page->is_product === true || $page->page_type === Page::TYPE_PRODUCT;
    }

    private function getRecapModelOverride(): ?string
    {
        $model = trim((string) env('LM_STUDIO_RECAP_MODEL', ''));

        return $model !== '' ? $model : null;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        return $s;
    }

    /**
     * Normalize predicted search queries into a single comma-separated line.
     * Must be 5-10 queries, comma-separated.
     */
    private function normalizePredictedSearchText(mixed $value): ?string
    {
        $s = $this->normalizeNullableText($value);
        if ($s === null) {
            return null;
        }

        $s = str_replace(["\r\n", "\n", "\r", ';'], ',', $s);

        $rawParts = array_map('trim', explode(',', $s));

        $seen = [];
        $queries = [];
        foreach ($rawParts as $part) {
            if ($part === '') {
                continue;
            }
            $key = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $queries[] = $part;
            if (count($queries) >= 10) {
                break;
            }
        }

        if (count($queries) < 5) {
            return null;
        }

        return implode(', ', $queries);
    }

    private function logSeparator(string $char = '═'): void
    {
        $this->line(str_repeat($char, 60));
    }

    private function buildContentPreview(string $contentWithTagsPurified, int $maxChars): string
    {
        $text = strip_tags($contentWithTagsPurified);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        return mb_substr($text, 0, max(1, $maxChars), 'UTF-8');
    }
}

