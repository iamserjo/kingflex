<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\Json\JsonParserService;
use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use App\Services\Redis\PageLockService;
use App\Services\TypeStructure\TypeStructureService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Detect whether a page is a product, whether it's available,
 * and map product_type to type_structures (product_type_id).
 *
 * Uses OpenAI-compatible API (LM Studio) with screenshots and retries
 * until it gets valid JSON with required keys.
 */
final class ProductTypeDetectionCommand extends Command
{
    protected $signature = 'page:product-type-detect
                            {--limit=1 : Number of pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--page= : Process specific page by ID}
                            {--force : Re-detect even if already detected}
                            {--max-attempts=0 : Max retry attempts per page (0 = unlimited)}
                            {--sleep-ms=0 : Sleep between retries in ms}';

    protected $description = 'Detect is_product, availability, and product_type_id for pages using screenshot + OpenAI-compatible API';

    private const string STAGE = 'product_type';

    public function __construct(
        private readonly LmStudioOpenApiService $openAi,
        private readonly PageLockService $lockService,
        private readonly JsonParserService $jsonParser,
        private readonly TypeStructureService $typeStructureService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $domainFilter = $this->option('domain');
        $pageId = $this->option('page');
        $force = (bool) $this->option('force');
        $maxAttempts = max(0, (int) $this->option('max-attempts'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));

        $this->logSeparator();
        $this->info('🤖 PRODUCT TYPE DETECTION (Screenshot + OpenAI-compatible API)');
        $this->info("⏰ Started at: " . now()->format('Y-m-d H:i:s'));
        $this->info("📡 API: {$this->openAi->getBaseUrl()}");
        $this->info("🧠 Model: {$this->openAi->getModel()}");
        $this->logSeparator();

        if (!$this->openAi->isConfigured()) {
            $this->error('❌ LM Studio OpenAPI is not configured. Check LM_STUDIO_OPENAPI_BASE_URL and LM_STUDIO_OPENAPI_MODEL in .env');
            return self::FAILURE;
        }

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

            $ok = $this->processPage($page, $maxAttempts, $sleepMs);
            $this->lockService->releaseLock($page->id, self::STAGE);

            return $ok ? self::SUCCESS : self::FAILURE;
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
            $ok = $this->processPage($page, $maxAttempts, $sleepMs);
            $this->lockService->releaseLock($page->id, self::STAGE);

            if ($ok) {
                $processed++;
            } else {
                $errors++;
            }

            $this->newLine();
        }

        $this->logSeparator();
        $this->info('✅ PRODUCT TYPE DETECTION COMPLETED');
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
        /** @var Builder<Page> $query */
        $query = Page::query()
            ->where('id', '>', $afterId)
            ->whereNotNull('screenshot_path')
            ->whereNotNull('last_crawled_at')
            ->orderBy('id');

        if (!$force) {
            $query->whereNull('product_type_detected_at');
        }

        if ($domain) {
            $query->whereHas('domain', fn ($q) => $q->where('domain', $domain));
        }

        return $query->first();
    }

    private function processPage(Page $page, int $maxAttempts, int $sleepMs): bool
    {
        $this->info("🔄 Detecting product type: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        if (empty($page->screenshot_path)) {
            $this->warn('   ⚠️  screenshot_path is empty; skipping');
            return false;
        }

        Log::info('🤖 [ProductTypeDetect] Starting', [
            'page_id' => $page->id,
            'url' => $page->url,
            'screenshot_path' => $page->screenshot_path,
        ]);

        try {
            $systemPrompt = (string) view('ai-prompts.product-type-detection')->render();

            $parts = [];
            $parts[] = "URL: " . $this->sanitizeUtf8($page->url);
            if (!empty($page->title)) {
                $parts[] = "Title: " . $this->sanitizeUtf8($page->title);
            }
            $content = implode("\n", $parts);
            $this->info('   📝 Context length (text only): ' . strlen($content) . ' chars');

            $imageDataUrl = $this->getScreenshotDataUrl($page);
            if ($imageDataUrl === null) {
                $this->warn('   ⚠️  Screenshot file does not exist; skipping');
                Log::warning('🤖 [ProductTypeDetect] Screenshot missing on disk', [
                    'page_id' => $page->id,
                    'screenshot_path' => $page->screenshot_path,
                ]);
                return false;
            }

            $requiredKeys = ['is_product', 'is_product_available', 'product_type'];

            $attempt = 0;
            while (true) {
                $attempt++;

                if ($maxAttempts > 0 && $attempt > $maxAttempts) {
                    $this->error("   ❌ Exceeded max attempts ({$maxAttempts})");
                    Log::error('🤖 [ProductTypeDetect] Exceeded max attempts', [
                        'page_id' => $page->id,
                        'attempts' => $attempt - 1,
                    ]);
                    return false;
                }

                $this->line("   🤖 Attempt #{$attempt}...");

                $response = $this->openAi->chatWithImage(
                    systemPrompt: $systemPrompt,
                    userText: $content,
                    imageDataUrl: $imageDataUrl,
                    options: [],
                );

                if ($response === null || empty($response['content'])) {
                    $logContext = [
                        'page_id' => $page->id,
                        'url' => $page->url,
                        'attempt' => $attempt,
                    ];

                    if ($response !== null && isset($response['error'])) {
                        $error = $response['error'];
                        $logContext['api_url'] = $error['url'] ?? 'unknown';
                        $logContext['api_path'] = $error['path'] ?? 'unknown';
                        $logContext['response_code'] = $error['status'] ?? 0;
                        $logContext['error_message'] = $error['message'] ?? 'unknown';
                        $logContext['error_body'] = $error['body'] ?? '';

                        $this->warn("   ⚠️  API Error (HTTP {$error['status']}): {$error['message']}");
                        $this->warn("   🔗 URL: {$error['url']}");
                        if (!empty($error['body'])) {
                            $this->warn("   📄 Response: " . substr($error['body'], 0, 200));
                        }
                    } else {
                        $this->warn('   ⚠️  Empty response from API');
                    }

                    Log::warning('🤖 [ProductTypeDetect] API returned empty response', $logContext);
                    $this->warn('   🔄 Retrying...');
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                $parsed = $this->jsonParser->parseWithKeys((string) $response['content'], $requiredKeys);

                if ($parsed === null) {
                    $this->warn('   ⚠️  Invalid JSON (missing keys), retrying...');
                    Log::warning('🤖 [ProductTypeDetect] Invalid JSON, retrying', [
                        'page_id' => $page->id,
                        'attempt' => $attempt,
                        'response_preview' => substr((string) $response['content'], 0, 400),
                    ]);
                    $this->sleepIfNeeded($sleepMs);
                    continue;
                }

                $isProduct = $this->toBool($parsed['is_product'] ?? null);
                $isAvailable = $this->toBool($parsed['is_product_available'] ?? null);
                $productTypeRaw = $parsed['product_type'] ?? null;
                $productType = is_string($productTypeRaw) ? trim($productTypeRaw) : null;
                if ($productType === '') {
                    $productType = null;
                }

                $update = [
                    'is_product' => $isProduct,
                    'product_type_detected_at' => now(),
                ];

                if ($isProduct === false) {
                    $update['is_product_available'] = null;
                    $update['product_type_id'] = null;
                } else {
                    $update['is_product_available'] = $isAvailable;
                    $update['product_type_id'] = $productType !== null
                        ? $this->typeStructureService->findExistingId($productType)
                        : null;
                }

                $page->update($update);

                $this->info('   ✅ Detection saved');

                Log::info('🤖 [ProductTypeDetect] ✅ Completed', [
                    'page_id' => $page->id,
                    'is_product' => $isProduct,
                    'is_product_available' => $update['is_product_available'] ?? null,
                    'product_type_id' => $update['product_type_id'] ?? null,
                    'attempts' => $attempt,
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            $this->error('   ❌ Exception: ' . $e->getMessage());
            Log::error('🤖 [ProductTypeDetect] Exception', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $v = mb_strtolower(trim($value), 'UTF-8');
            return in_array($v, ['1', 'true', 'yes', 'y', 'да'], true);
        }

        return false;
    }

    /**
     * Sanitize string to ensure valid UTF-8 encoding.
     * Removes or replaces invalid UTF-8 sequences that would cause json_encode to fail.
     */
    private function sanitizeUtf8(string $text): string
    {
        // First attempt: convert encoding to fix malformed UTF-8
        $sanitized = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Second attempt: use iconv with //IGNORE to remove invalid sequences
        $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $sanitized);
        
        // Third attempt: remove null bytes and other control characters that might cause issues
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized);
        
        return $sanitized ?: '';
    }

    private function sleepIfNeeded(int $sleepMs): void
    {
        if ($sleepMs <= 0) {
            return;
        }

        usleep($sleepMs * 1000);
    }

    /**
     * Resolve screenshot_path to a data URL suitable for OpenAI-compatible image_url payload.
     *
     * Supports:
     * - Storage disk "local" (may be rooted at storage/app/private)
     * - Legacy Stage 1 path under storage/app/
     * - Absolute paths (if screenshot_path was saved as full path)
     */
    private function getScreenshotDataUrl(Page $page): ?string
    {
        $path = (string) ($page->screenshot_path ?? '');
        if ($path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = in_array($extension, ['jpg', 'jpeg'], true) ? 'image/jpeg' : 'image/png';

        // 1) Preferred: Laravel local disk
        if (Storage::disk('local')->exists($path)) {
            $binary = Storage::disk('local')->get($path);
            return "data:{$mime};base64," . base64_encode($binary);
        }

        // 2) Stage 1 currently stores under storage/app/{relative}
        $legacyFullPath = storage_path('app/' . ltrim($path, '/'));
        if (file_exists($legacyFullPath)) {
            $binary = file_get_contents($legacyFullPath);
            if ($binary === false) {
                return null;
            }
            return "data:{$mime};base64," . base64_encode($binary);
        }

        // 3) Absolute path stored in DB
        if (str_starts_with($path, '/') && file_exists($path)) {
            $binary = file_get_contents($path);
            if ($binary === false) {
                return null;
            }
            return "data:{$mime};base64," . base64_encode($binary);
        }

        return null;
    }

    private function logSeparator(string $char = '═'): void
    {
        $this->line(str_repeat($char, 60));
    }
}






