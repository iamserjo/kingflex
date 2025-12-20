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
            // Default behavior: process new pages, PLUS backfill pages that were already detected
            // but ended up with product_type_id = null (common when type mapping logic changed).
            $query->where(function (Builder $q) {
                $q->whereNull('product_type_detected_at')
                    ->orWhere(function (Builder $q2) {
                        $q2->where('is_product', true)
                            ->whereNotNull('product_type_detected_at')
                            ->whereNull('product_type_id');
                    })
                    // Backfill obvious filter/listing URLs that were previously misclassified as products.
                    ->orWhere(function (Builder $q3) {
                        $q3->whereNotNull('product_type_detected_at')
                            ->where('is_product', true)
                            ->where('url', 'like', '%/ch-%');
                    });
            });
        }

        if ($domain) {
            $query->whereHas('domain', fn ($q) => $q->where('domain', $domain));
        }

        return $query->first();
    }

    private function processPage(Page $page, int $maxAttempts, int $sleepMs): bool
    {
        $startedAt = microtime(true);

        $this->info("🔄 Detecting product type: {$page->url}");
        $this->info("   Page ID: {$page->id}");

        if (empty($page->screenshot_path)) {
            $this->warn('   ⚠️  screenshot_path is empty; skipping');
            $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));
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
            if (!empty($page->meta_description)) {
                $parts[] = "Meta description: " . $this->sanitizeUtf8($page->meta_description);
            }
            if (!empty($page->content_with_tags_purified)) {
                $preview = $this->buildContentPreview((string) $page->content_with_tags_purified, 2000);
                if ($preview !== '') {
                    $parts[] = "Content preview: " . $this->sanitizeUtf8($preview);
                }
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
                $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));
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

                // Safety override: filter/listing URLs should never be treated as a single product page.
                if ($this->isObviousListingUrl((string) $page->url)) {
                    Log::info('🤖 [ProductTypeDetect] Overriding is_product=false due to listing-like URL', [
                        'page_id' => $page->id,
                        'url' => $page->url,
                        'model_is_product' => $isProduct,
                        'model_product_type' => $productType,
                    ]);
                    $this->line('   🛡️  URL looks like listing/filter; forcing is_product=false');
                    $isProduct = false;
                    $isAvailable = false;
                    $productType = null;
                }

                // Deterministic availability override from page text (helps when the model misses "В наявності/Купити").
                if ($isProduct === true) {
                    $availabilityFromText = $this->guessAvailabilityFromText(
                        metaDescription: (string) ($page->meta_description ?? ''),
                        contentWithTagsPurified: (string) ($page->content_with_tags_purified ?? ''),
                    );

                    if ($availabilityFromText !== null && $availabilityFromText !== $isAvailable) {
                        Log::info('🤖 [ProductTypeDetect] Overriding availability from page text', [
                            'page_id' => $page->id,
                            'url' => $page->url,
                            'model_is_product_available' => $isAvailable,
                            'text_is_product_available' => $availabilityFromText,
                        ]);
                        $this->line('   🛡️  Availability overridden from page text');
                        $isAvailable = $availabilityFromText;
                    }
                }

                $this->line('   🔎 Parsed: is_product=' . ($isProduct ? 'true' : 'false')
                    . ', is_product_available=' . ($isAvailable ? 'true' : 'false')
                    . ', product_type=' . ($productType ?? 'null'));

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
                        ? $this->resolveProductTypeId($productType, $page->id)
                        : null;
                }

                $page->update($update);

                $this->info('   ✅ Detection saved');
                $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));

                Log::info('🤖 [ProductTypeDetect] ✅ Completed', [
                    'page_id' => $page->id,
                    'is_product' => $isProduct,
                    'is_product_available' => $update['is_product_available'] ?? null,
                    'product_type_id' => $update['product_type_id'] ?? null,
                    'attempts' => $attempt,
                    'took_seconds' => round(microtime(true) - $startedAt, 3),
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            $this->error('   ❌ Exception: ' . $e->getMessage());
            $this->line('   ⏱️  Took: ' . $this->formatSeconds(microtime(true) - $startedAt));
            Log::error('🤖 [ProductTypeDetect] Exception', [
                'page_id' => $page->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'took_seconds' => round(microtime(true) - $startedAt, 3),
            ]);
            return false;
        }
    }

    /**
     * Heuristic for URLs that are almost certainly category/listing/filter pages.
     */
    private function isObviousListingUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $path = mb_strtolower($path, 'UTF-8');

        // Common filter pattern used by many ecommerce sites (including ti.ua): /ch-...
        if (str_contains($path, '/ch-')) {
            return true;
        }

        // Common listing endpoints
        foreach (['/search', '/catalog', '/category', '/categories', '/filters'] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
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

    /**
     * Try to infer if the product can be bought/ordered right now based on text cues.
     *
     * @return bool|null null = unknown/ambiguous
     */
    private function guessAvailabilityFromText(string $metaDescription, string $contentWithTagsPurified): ?bool
    {
        $text = trim($metaDescription . "\n" . $contentWithTagsPurified);
        if ($text === '') {
            return null;
        }

        $t = mb_strtolower($text, 'UTF-8');

        // Explicit negative phrases should dominate even if they contain positive substrings
        // (e.g. "немає в наявності" contains "в наявності").
        foreach ([
            'немає в наявності',
            'нет в наличии',
            'не в наличии',
            'відсут',
            'out of stock',
            'sold out',
            'unavailable',
            'нету в наличии',
        ] as $needle) {
            if (str_contains($t, $needle)) {
                return false;
            }
        }

        foreach ([
            'в наявності',
            'є в наявності',
            'есть в наличии',
            'available',
            'in stock',
            'купити',
            'купить',
            'в корзину',
            'add to cart',
            'buy now',
            'оформити',
            'оформить',
            'предзаказ',
            'preorder',
        ] as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }

        return null;
    }

    /**
     * Try to map AI product_type to an existing TypeStructure id.
     *
     * The AI may return a single value (ideal) or a delimited list like:
     * - "phone|tablet|case"
     * - "phone, tablet"
     * - "тип: телефон"
     *
     * We try multiple candidates (in order) and return the first match.
     */
    private function resolveProductTypeId(string $productType, int $pageIdForLog): ?int
    {
        $candidates = $this->extractProductTypeCandidates($productType);

        // 1) Prefer any existing match without creating new rows.
        foreach ($candidates as $candidate) {
            $id = $this->typeStructureService->findExistingId($candidate);
            if ($id !== null) {
                return $id;
            }
        }

        // 2) If nothing matched, create a minimal type structure for the first candidate
        // and attach it to the page.
        $first = $candidates[0] ?? null;
        if ($first === null || trim($first) === '') {
            Log::warning('🤖 [ProductTypeDetect] Could not map product_type to type_structures (no candidates)', [
                'page_id' => $pageIdForLog,
                'product_type_raw' => $productType,
            ]);
            return null;
        }

        $createdId = $this->typeStructureService->findOrCreateId($first);
        if ($createdId === null) {
            Log::warning('🤖 [ProductTypeDetect] Could not create type_structures row for product_type', [
                'page_id' => $pageIdForLog,
                'product_type_raw' => $productType,
                'candidate' => $first,
            ]);
            return null;
        }

        Log::info('🤖 [ProductTypeDetect] Ensured type_structures row for product_type', [
            'page_id' => $pageIdForLog,
            'product_type_raw' => $productType,
            'candidate' => $first,
            'product_type_id' => $createdId,
        ]);

        return $createdId;
    }

    /**
     * @return list<string>
     */
    private function extractProductTypeCandidates(string $productType): array
    {
        $v = trim($productType);

        // Remove common wrappers/quotes.
        $v = trim($v, " \t\n\r\0\x0B\"'`");
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        $v = trim($v);

        // Remove common prefixes like "type:" / "product type:" / "тип товара:".
        $vNoPrefix = preg_replace('/^(?:product\s*type|type|тип\s*товара|тип)\s*[:\-]\s*/ui', '', $v) ?? $v;
        $vNoPrefix = trim($vNoPrefix);

        // Remove parenthesized clarifications: "phone (smartphone)" -> "phone"
        $vNoParens = preg_replace('/\s*\(.*?\)\s*/u', ' ', $vNoPrefix) ?? $vNoPrefix;
        $vNoParens = preg_replace('/\s+/u', ' ', $vNoParens) ?? $vNoParens;
        $vNoParens = trim($vNoParens);

        $base = $vNoParens !== '' ? $vNoParens : $vNoPrefix;
        $base = trim($base);

        // Split by common delimiters (but NOT by spaces).
        $parts = preg_split('/\s*(?:\||,|;|\/|\\\\|\bor\b|\bили\b|\n)\s*/ui', $base, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === []) {
            $parts = [$base];
        }

        $candidates = [];

        // Try in this order:
        // 1) each split part
        // 2) full base (in case splitting was too aggressive)
        foreach ($parts as $p) {
            if (!is_string($p)) {
                continue;
            }
            $p = trim($p, " \t\n\r\0\x0B\"'`");
            $p = preg_replace('/\s+/u', ' ', $p) ?? $p;
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $candidates[] = $p;

            // Add common normalization variants to improve matching:
            // - gaming_console -> gaming console
            // - then also try the last token ("console") if it's compound.
            $spaced = str_replace(['_', '-'], ' ', $p);
            $spaced = preg_replace('/\s+/u', ' ', $spaced) ?? $spaced;
            $spaced = trim($spaced);
            if ($spaced !== '' && $spaced !== $p) {
                $candidates[] = $spaced;
            }

            $tokens = preg_split('/\s+/u', $spaced !== '' ? $spaced : $p, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($tokens) && count($tokens) >= 2) {
                $last = trim((string) end($tokens));
                if ($last !== '') {
                    $candidates[] = $last;
                }
            }
        }

        if ($base !== '' && !in_array($base, $candidates, true)) {
            $candidates[] = $base;
        }

        // De-dup while preserving order.
        $unique = [];
        foreach ($candidates as $c) {
            if (!in_array($c, $unique, true)) {
                $unique[] = $c;
            }
        }

        return $unique;
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

            // Common negative signals (models sometimes return labels instead of booleans).
            if (str_contains($v, 'out of stock')
                || str_contains($v, 'sold out')
                || str_contains($v, 'unavailable')
                || str_contains($v, 'нет в наличии')
                || str_contains($v, 'немає в наявності')
                || str_contains($v, 'не в наличии')
                || str_contains($v, 'нету в наличии')
                || str_contains($v, 'відсут')
            ) {
                return false;
            }

            // Common positive signals.
            if (in_array($v, ['1', 'true', 'yes', 'y', 'да'], true)) {
                return true;
            }

            if (str_contains($v, 'in stock')
                || str_contains($v, 'available')
                || str_contains($v, 'в наличии')
                || str_contains($v, 'є в наявності')
                || str_contains($v, 'наявн')
                || str_contains($v, 'купить')
                || str_contains($v, 'в корзину')
                || str_contains($v, 'add to cart')
                || str_contains($v, 'buy now')
                || str_contains($v, 'preorder')
                || str_contains($v, 'предзаказ')
            ) {
                return true;
            }

            return false;
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

    private function formatSeconds(float $seconds): string
    {
        return number_format(max(0.0, $seconds), 2) . 's';
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

        // 1) Preferred: Laravel local disk
        if (Storage::disk('local')->exists($path)) {
            $binary = Storage::disk('local')->get($path);
            return $this->toCroppedDataUrl($binary);
        }

        // 2) Stage 1 currently stores under storage/app/{relative}
        $legacyFullPath = storage_path('app/' . ltrim($path, '/'));
        if (file_exists($legacyFullPath)) {
            $binary = file_get_contents($legacyFullPath);
            if ($binary === false) {
                return null;
            }
            return $this->toCroppedDataUrl($binary);
        }

        // 3) Absolute path stored in DB
        if (str_starts_with($path, '/') && file_exists($path)) {
            $binary = file_get_contents($path);
            if ($binary === false) {
                return null;
            }
            return $this->toCroppedDataUrl($binary);
        }

        return null;
    }

    /**
     * Convert screenshot bytes to a data URL after cropping to max 2000px height.
     * This does NOT write anything to disk.
     */
    private function toCroppedDataUrl(string $binary): ?string
    {
        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false) {
            return null;
        }

        $mime = (string) ($imageInfo['mime'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return null;
        }

        // Crop for AI when GD is available:
        // - keep from top (y=0)
        // - crop to max height 1500px
        //
        // Important: do not require GD in runtime/tests. If GD is missing, fall back
        // to original bytes (still useful for vision models).
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return "data:{$mime};base64," . base64_encode($binary);
        }

        $croppedBinary = $this->cropImageBinaryForAi($binary, $mime, 0, 3500);
        if ($croppedBinary === null) {
            $croppedBinary = $binary;
        }

        return "data:{$mime};base64," . base64_encode($croppedBinary);
    }

    /**
     * Crop image to a specific viewport and return encoded bytes.
     *
     * Rules:
     * - If image is too short to remove $topCropPx: return original bytes (don't fail).
     * - If resulting crop equals original (no crop): return original bytes (avoid re-encode).
     * - Otherwise crop from Y=$topCropPx and keep up to $targetHeightPx.
     */
    private function cropImageBinaryForAi(string $binary, string $mime, int $topCropPx, int $targetHeightPx): ?string
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $topCropPx = max(0, $topCropPx);
        $targetHeightPx = max(1, $targetHeightPx);

        // Not enough height to remove the header area - keep original
        if ($height <= $topCropPx) {
            imagedestroy($source);
            return $binary;
        }

        $startY = $topCropPx;
        $cropHeight = min($targetHeightPx, $height - $startY);
        if ($cropHeight <= 0) {
            imagedestroy($source);
            return $binary;
        }

        // No-op crop (keep original bytes)
        if ($startY === 0 && $cropHeight === $height) {
            imagedestroy($source);
            return $binary;
        }

        $cropped = imagecreatetruecolor($width, $cropHeight);
        if ($cropped === false) {
            imagedestroy($source);
            return null;
        }

        // Preserve transparency for PNG
        if ($mime === 'image/png') {
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            $transparent = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
            imagefilledrectangle($cropped, 0, 0, $width, $cropHeight, $transparent);
        }

        // Crop: copy from (0, startY) to (0, 0)
        imagecopy($cropped, $source, 0, 0, 0, $startY, $width, $cropHeight);
        imagedestroy($source);

        $obLevel = ob_get_level();
        ob_start();
        try {
            if ($mime === 'image/jpeg') {
                imagejpeg($cropped, null, 85);
            } else {
                imagepng($cropped);
            }
            $out = ob_get_clean();
        } finally {
            imagedestroy($cropped);
            while (ob_get_level() > $obLevel) {
                @ob_end_clean();
            }
        }

        if (!is_string($out) || $out === '') {
            return null;
        }

        return $out;
    }

    private function logSeparator(string $char = '═'): void
    {
        $this->line(str_repeat($char, 60));
    }
}









