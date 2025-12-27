<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\Json\JsonParserService;
use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use App\Services\Pages\PageAttributesCandidateService;
use App\Services\Pages\PageScreenshotDataUrlService;
use App\Services\Redis\PageLockService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Extract product attributes from page screenshot (vision) into:
 * - pages.json_attributes (follows type_structures.structure)
 * - pages.product_original_article / pages.product_model_number
 * - pages.attributes_extracted_at
 * - pages.product_metadata_extracted_at
 */
final class ExtractPageAttributesCommand extends Command
{
    protected $signature = 'page:extract-attributes
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}
                            {--force : Re-extract even if already extracted}
                            {--attempts=5 : Max retry attempts per page}
                            {--max-attempts= : (Deprecated) Max retry attempts per page}
                            {--sleep-ms=0 : Sleep between retries in ms}';

    protected $description = 'Extract product attributes + original article/model number from screenshot using OpenAI-compatible vision model (LM Studio OpenAPI)';

    private const  STAGE = 'attributes';

    private const  MAX_USER_CONTENT_CHARS = 4000;

    public function __construct(
        private readonly LmStudioOpenApiService $openAi,
        private readonly PageLockService $lockService,
        private readonly PageAttributesCandidateService $candidates,
        private readonly PageScreenshotDataUrlService $screenshotDataUrl,
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
        $maxAttemptsDeprecated = $this->option('max-attempts');
        if ($maxAttemptsDeprecated !== null && $maxAttemptsDeprecated !== '') {
            $maxAttempts = max(1, (int) $maxAttemptsDeprecated);
        }
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        $this->logSeparator();
        $this->info('🧩 EXTRACT PAGE ATTRIBUTES (Screenshot + type_structures.structure)');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $this->info("📡 API: {$this->openAi->getBaseUrl()}");
        $this->info("🧠 Vision model: {$this->openAi->getVisionModel()}");
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
            Log::error('🧩 [ExtractAttributes] Fatal error, stopping command', [
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
            Log::error('🧩 [ExtractAttributes] Fatal error, stopping command', [
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }

        $this->logSeparator();
        $this->info('✅ EXTRACT PAGE ATTRIBUTES COMPLETED');
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

        $this->info("🔄 Extracting attributes: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        if (!$force && $page->attributes_extracted_at !== null) {
            $this->line('   ⏭️  Already extracted, skipping (use --force to re-run)');
            return true;
        }

        if (empty($page->screenshot_path)) {
            $this->warn('   ⚠️  screenshot_path is empty; skipping');
            return false;
        }

        if ($page->product_type_id === null) {
            // As requested: do not mark as extracted; leave for later when product type is detected.
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

        Log::info('🧩 [ExtractAttributes] Starting', [
            'page_id' => $page->id,
            'url' => $page->url,
            'product_type_id' => $page->product_type_id,
            'screenshot_path' => $page->screenshot_path,
        ]);

        try {
            $systemPrompt = (string) view('ai-prompts.extract-page-attributes', [
                'structure' => $structure,
            ])->render();

            $userText = $this->buildUserTextForVision($page);
            $this->info('   📝 Context length (text only): ' . strlen($userText) . ' chars');

            $imageDataUrl = $this->screenshotDataUrl->forPage($page, 0);
            if ($imageDataUrl === null) {
                $this->warn('   ⚠️  Screenshot file does not exist or unsupported; skipping');
                Log::warning('🧩 [ExtractAttributes] Screenshot missing/unsupported on disk', [
                    'page_id' => $page->id,
                    'screenshot_path' => $page->screenshot_path,
                ]);
                return false;
            }

            // Required keys. product_original_article is preferred, but we also accept legacy product_code.
            $requiredKeys = ['product_model_number', 'attributes'];

            $attempt = 0;
            while (true) {
                $attempt++;

                if ($attempt > $maxAttempts) {
                    $this->error("   ❌ Exceeded max attempts ({$maxAttempts})");
                    Log::error('🧩 [ExtractAttributes] Exceeded max attempts', [
                        'page_id' => $page->id,
                        'attempts' => $attempt - 1,
                    ]);
                    return false;
                }

                $this->line("   🤖 Attempt #{$attempt}...");

                $options = [
                    'response_format' => ['type' => 'text'],
                ];

                $response = $this->openAi->chatWithImage(
                    systemPrompt: $systemPrompt,
                    userText: $userText,
                    imageDataUrl: $imageDataUrl,
                    options: $options,
                );

                if ($response === null) {
                    throw new RuntimeException('LM Studio is unavailable: API returned null response');
                }

                if (isset($response['error'])) {
                    $error = $response['error'];
                    $status = $error['status'] ?? null;
                    $message = $error['message'] ?? 'unknown';
                    $url = $error['url'] ?? 'unknown';
                    $body = (string) ($error['body'] ?? '');

                    // Fail-fast when LM Studio is unreachable (timeouts, connection refused, DNS, etc.).
                    if ($status === null || $status === 0) {
                        throw new RuntimeException("LM Studio is unavailable: {$message}");
                    }

                    $this->warn("   ⚠️  API Error" . ($status !== null ? " (HTTP {$status})" : '') . ": {$message}");
                    $this->warn("   🔗 URL: {$url}");
                    if ($body !== '') {
                        $this->warn("   📄 Response: " . substr($body, 0, 400));
                    }

                    Log::warning('🧩 [ExtractAttributes] API error, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'error' => $error,
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                $content = (string) ($response['content'] ?? '');
                if ($content === '') {
                    $this->warn('   ⚠️  Empty assistant content, retrying...');
                    Log::warning('🧩 [ExtractAttributes] Empty assistant content, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                /** @var JsonParserService $jsonParser */
                $jsonParser = app(JsonParserService::class);
                $parsed = $jsonParser->parse($content);
                if ($parsed === null) {
                    $this->warn('   ⚠️  Invalid JSON content, retrying...');
                    Log::warning('🧩 [ExtractAttributes] Invalid JSON content, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'response_preview' => substr($content, 0, 400),
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                foreach ($requiredKeys as $key) {
                    if (!array_key_exists($key, $parsed)) {
                        $this->warn("   ⚠️  Missing key '{$key}', retrying...");
                        Log::warning('🧩 [ExtractAttributes] Missing required key, retrying', [
                            'page_id' => $page->id,
                            'attempt' => $attempt,
                            'missing_key' => $key,
                            'available_keys' => array_keys($parsed),
                        ]);
                        $this->sleepIfNeeded($sleepMs);
                        continue 2;
                    }
                }

                if (!array_key_exists('product_original_article', $parsed) && !array_key_exists('product_code', $parsed)) {
                    $this->warn("   ⚠️  Missing key 'product_original_article' (or legacy 'product_code'), retrying...");
                    Log::warning('🧩 [ExtractAttributes] Missing product_original_article/product_code key, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'available_keys' => array_keys($parsed),
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                if (!is_array($parsed['attributes'])) {
                    $this->warn("   ⚠️  'attributes' must be an object/array, retrying...");
                    Log::warning('🧩 [ExtractAttributes] Invalid attributes type, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'attributes_type' => gettype($parsed['attributes']),
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                // Backward-compatible: accept legacy "product_code" key if prompt/model returns it.
                $productOriginalArticleRaw = $parsed['product_original_article'] ?? ($parsed['product_code'] ?? null);
                $productOriginalArticle = $this->normalizeNullableString($productOriginalArticleRaw, 128);
                $modelNumber = $this->normalizeNullableString($parsed['product_model_number'] ?? null, 128);

                $page->update([
                    'json_attributes' => $parsed['attributes'],
                    'product_original_article' => $productOriginalArticle,
                    'product_model_number' => $modelNumber,
                    'attributes_extracted_at' => now(),
                    'product_metadata_extracted_at' => now(),
                ]);

                $this->info('   ✅ Attributes saved');
                $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));

                Log::info('🧩 [ExtractAttributes] ✅ Completed', [
                    'page_id' => $page->id,
                    'attempts' => $attempt,
                    'took_seconds' => round(microtime(true) - $startedAt, 3),
                    'has_product_original_article' => $productOriginalArticle !== null,
                    'has_product_model_number' => $modelNumber !== null,
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            $this->error('   ❌ Exception: ' . $e->getMessage());
            $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));
            Log::error('🧩 [ExtractAttributes] Exception', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'took_seconds' => round(microtime(true) - $startedAt, 3),
            ]);
            return false;
        }
    }

    private function buildUserTextForVision(Page $page): string
    {
        $parts = [];
        $parts[] = 'URL: ' . (string) $page->url;
        if (!empty($page->title)) {
            $parts[] = 'Title: ' . (string) $page->title;
        }
        if (!empty($page->meta_description)) {
            $parts[] = 'Meta description: ' . (string) $page->meta_description;
        }

        $content = implode("\n", $parts);

        if (strlen($content) > self::MAX_USER_CONTENT_CHARS) {
            $content = substr($content, 0, self::MAX_USER_CONTENT_CHARS) . "\n... [truncated]";
        }

        return $content;
    }

    // Screenshot data URL is now provided by App\Services\Pages\PageScreenshotDataUrlService (S3-only).

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





