<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\Json\JsonParserService;
use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use App\Services\Pages\PageAttributesCandidateService;
use App\Services\Redis\PageLockService;
use App\Services\Storage\PageAssetsStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Extract product attributes from purified HTML text (no vision/screenshot) into:
 * - pages.json_attributes (follows type_structures.structure)
 * - pages.sku / pages.product_code / pages.product_model_number
 * - pages.attributes_extracted_at
 *
 * This command is text-only alternative to page:extract-attributes (which uses vision model).
 */
final class ExtractPageAttributesWithTextV1Command extends Command
{
    protected $signature = 'page:extract-attributes-with-text-v1
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}
                            {--force : Re-extract even if already extracted}
                            {--attempts=5 : Max retry attempts per page}
                            {--sleep-ms=0 : Sleep between retries in ms}';

    protected $description = 'Extract product attributes + SKU/product_code/model_number from purified HTML text using LM Studio OpenAPI (text-only, no vision)';

    private const string STAGE = 'attributes_text_v1';

    private const int MAX_PURIFIED_HTML_CHARS = 32000;

    private const int MAX_USER_CONTENT_CHARS = 4000;

    public function __construct(
        private readonly LmStudioOpenApiService $openAi,
        private readonly PageLockService $lockService,
        private readonly PageAttributesCandidateService $candidates,
        private readonly PageAssetsStorageService $assetsStorage,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $domainFilter = $this->option('domain');
        $pageId = $this->option('page');
        $force = (bool) $this->option('force');
        $maxAttempts = max(1, (int) $this->option('attempts'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        $this->logSeparator();
        $this->info('🧩 EXTRACT PAGE ATTRIBUTES (Text-only, from purified HTML)');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $this->info("📡 API: {$this->openAi->getBaseUrl()}");
        $this->info("🧠 Model: {$this->openAi->getModel()}");
        $this->logSeparator();

        if (!$this->openAi->isConfigured()) {
            $this->error('❌ LM Studio OpenAPI is not configured. Check LM_STUDIO_OPENAPI_BASE_URL and LM_STUDIO_OPENAPI_MODEL in .env');
            return self::FAILURE;
        }

        try {
            // Specific page by ID
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

                $ok = $this->processPage($page, $force, $maxAttempts, $sleepMs);
                $this->lockService->releaseLock($page->id, self::STAGE);

                return $ok ? self::SUCCESS : self::FAILURE;
            }
        } catch (RuntimeException $e) {
            $this->error('❌ ' . $e->getMessage());
            Log::error('🧩 [ExtractAttributesTextV1] Fatal error, stopping command', [
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $processed = 0;
        $errors = 0;
        $skipped = 0;

        $lastProcessedId = 0;

        try {
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
        } catch (RuntimeException $e) {
            $this->error('❌ ' . $e->getMessage());
            Log::error('🧩 [ExtractAttributesTextV1] Fatal error, stopping command', [
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $this->logSeparator();
        $this->info('✅ EXTRACT PAGE ATTRIBUTES (Text V1) COMPLETED');
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
        return $this->candidates->nextCandidate(
            afterId: $afterId,
            domain: $domain,
            force: $force,
        );
    }

    private function processPage(Page $page, bool $force, int $maxAttempts, int $sleepMs): bool
    {
        $startedAt = microtime(true);

        $this->info("🔄 Extracting attributes (text): {$page->url}");
        $this->info("   Page ID: {$page->id}");

        if (!$force && $page->attributes_extracted_at !== null) {
            $this->line('   ⏭️  Already extracted, skipping (use --force to re-run)');
            return true;
        }

        if (empty($page->content_with_tags_purified)) {
            $this->warn('   ⚠️  content_with_tags_purified is empty; skipping');
            return false;
        }

        if ($page->product_type_id === null) {
            // Do not mark as extracted; leave for later when product type is detected.
            $this->warn('   ⚠️  product_type_id is null; skipping (will retry later)');
            return false;
        }

        $typeStructure = TypeStructure::query()->find($page->product_type_id);
        if ($typeStructure === null) {
            $this->warn("   ⚠️  type_structures row missing for product_type_id={$page->product_type_id}; skipping");
            return false;
        }

        $structure = (array) ($typeStructure->structure ?? []);
        if ($structure === []) {
            $this->warn("   ⚠️  type_structures.structure is empty for product_type_id={$page->product_type_id}; skipping");
            return false;
        }

        Log::info('🧩 [ExtractAttributesTextV1] Starting', [
            'page_id' => $page->id,
            'url' => $page->url,
            'product_type_id' => $page->product_type_id,
        ]);

        try {
            // Fetch purified HTML from S3
            $s3StartTime = microtime(true);
            $purifiedHtml = $this->fetchPurifiedHtml($page);
            $s3Time = microtime(true) - $s3StartTime;

            if ($purifiedHtml === null) {
                $this->warn('   ⚠️  Failed to fetch purified HTML from S3; skipping');
                return false;
            }

            $this->info('   📦 S3 fetch: ' . strlen($purifiedHtml) . ' chars in ' . $this->formatSeconds($s3Time));

            $systemPrompt = (string) view('ai-prompts.extract-page-attributes-text-v1', [
                'structure' => $structure,
            ])->render();

            $userText = $this->buildUserTextContent($page, $purifiedHtml);
            $this->info('   📝 User content length: ' . strlen($userText) . ' chars');

            $requiredKeys = ['sku', 'product_code', 'product_model_number', 'used', 'attributes'];

            $attempt = 0;
            while (true) {
                $attempt++;

                if ($attempt > $maxAttempts) {
                    $this->error("   ❌ Exceeded max attempts ({$maxAttempts})");
                    Log::error('🧩 [ExtractAttributesTextV1] Exceeded max attempts', [
                        'page_id' => $page->id,
                        'attempts' => $attempt - 1,
                    ]);
                    return false;
                }

                $this->line("   🤖 Attempt #{$attempt}...");

                // Use text-only chat method (no vision)
                $response = $this->openAi->chatJson($systemPrompt, $userText, ['model' => 'google/gemma-3-4b']);

                if ($response === null) {
                    throw new RuntimeException('LM Studio is unavailable: API returned null response');
                }

                if (isset($response['error'])) {
                    $error = $response['error'];
                    $status = $error['status'] ?? null;
                    $message = $error['message'] ?? 'unknown';

                    // Fail-fast when LM Studio is unreachable
                    if ($status === null || $status === 0) {
                        throw new RuntimeException("LM Studio is unavailable: {$message}");
                    }

                    $this->warn("   ⚠️  API Error" . ($status !== null ? " (HTTP {$status})" : '') . ": {$message}");

                    Log::warning('🧩 [ExtractAttributesTextV1] API error, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'error' => $error,
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                // chatJson already parses JSON and returns array or null
                $parsed = $response;

                foreach ($requiredKeys as $key) {
                    if (!array_key_exists($key, $parsed)) {
                        $this->warn("   ⚠️  Missing key '{$key}', retrying...");
                        Log::warning('🧩 [ExtractAttributesTextV1] Missing required key, retrying', [
                            'page_id' => $page->id,
                            'attempt' => $attempt,
                            'missing_key' => $key,
                            'available_keys' => array_keys($parsed),
                        ]);
                        $this->sleepIfNeeded($sleepMs);
                        continue 2;
                    }
                }

                if (!is_array($parsed['attributes'])) {
                    $this->warn("   ⚠️  'attributes' must be an object/array, retrying...");
                    Log::warning('🧩 [ExtractAttributesTextV1] Invalid attributes type, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'attributes_type' => gettype($parsed['attributes']),
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                $sku = $this->normalizeNullableString($parsed['sku'] ?? null, 128);
                $productCode = $this->normalizeNullableString($parsed['product_code'] ?? null, 128);
                $modelNumber = $this->normalizeNullableString($parsed['product_model_number'] ?? null, 128);
                $isUsed = $this->normalizeNullableBool($parsed['used'] ?? null);

                $page->update([
                    'json_attributes' => $parsed['attributes'],
                    'sku' => $sku,
                    'product_code' => $productCode,
                    'product_model_number' => $modelNumber,
                    'is_used' => $isUsed,
                    'attributes_extracted_at' => now(),
                ]);

                $this->info('   ✅ Attributes saved');
                $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));

                Log::info('🧩 [ExtractAttributesTextV1] ✅ Completed', [
                    'page_id' => $page->id,
                    'attempts' => $attempt,
                    'took_seconds' => round(microtime(true) - $startedAt, 3),
                    'has_sku' => $sku !== null,
                    'has_product_code' => $productCode !== null,
                    'has_product_model_number' => $modelNumber !== null,
                    'is_used' => $isUsed,
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            $this->error('   ❌ Exception: ' . $e->getMessage());
            $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));
            Log::error('🧩 [ExtractAttributesTextV1] Exception', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'took_seconds' => round(microtime(true) - $startedAt, 3),
            ]);
            return false;
        }
    }

    /**
     * Fetch purified HTML content from S3.
     */
    private function fetchPurifiedHtml(Page $page): ?string
    {
        $url = $page->content_with_tags_purified;
        if (empty($url)) {
            return null;
        }

        try {
            $content = $this->assetsStorage->getTextFromUrl($url);
            if ($content === '') {
                Log::warning('🧩 [ExtractAttributesTextV1] Purified HTML is empty', [
                    'page_id' => $page->id,
                    'url' => $url,
                ]);
                return null;
            }
            return $content;
        } catch (\Throwable $e) {
            Log::error('🧩 [ExtractAttributesTextV1] Failed to fetch purified HTML from S3', [
                'page_id' => $page->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build user content for text-only chat.
     * Includes page metadata + purified HTML content.
     */
    private function buildUserTextContent(Page $page, string $purifiedHtml): string
    {
        $parts = [];
        $parts[] = 'URL: ' . (string) $page->url;
        if (!empty($page->title)) {
            $parts[] = 'Title: ' . (string) $page->title;
        }
        if (!empty($page->meta_description)) {
            $parts[] = 'Meta description: ' . (string) $page->meta_description;
        }

        $metaPart = implode("\n", $parts);

        // Truncate metadata if too long
        if (strlen($metaPart) > self::MAX_USER_CONTENT_CHARS) {
            $metaPart = substr($metaPart, 0, self::MAX_USER_CONTENT_CHARS) . "\n... [metadata truncated]";
        }

        // Truncate purified HTML if too long
        $htmlPart = $purifiedHtml;
        if (strlen($htmlPart) > self::MAX_PURIFIED_HTML_CHARS) {
            $htmlPart = substr($htmlPart, 0, self::MAX_PURIFIED_HTML_CHARS) . "\n... [content truncated]";
        }

        return $metaPart . "\n\n--- Page Content (purified HTML) ---\n\n" . $htmlPart;
    }

    private function normalizeNullableString(mixed $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        if (mb_strlen($s, 'UTF-8') > $maxLen) {
            $s = mb_substr($s, 0, $maxLen, 'UTF-8');
        }

        return $s;
    }

    private function normalizeNullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        return null;
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

