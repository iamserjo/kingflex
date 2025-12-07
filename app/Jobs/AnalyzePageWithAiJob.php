<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Article;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Page;
use App\Models\Product;
use App\Services\Html\HtmlSanitizerService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to analyze a page using AI and extract structured data.
 */
class AnalyzePageWithAiJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Page $page,
        public bool $useScreenshot = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(OpenRouterService $openRouter, HtmlSanitizerService $sanitizer): void
    {
        if (!$openRouter->isConfigured()) {
            Log::warning('OpenRouter is not configured, skipping AI analysis', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        Log::info('🤖 Starting AI analysis for page', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'use_screenshot' => $this->useScreenshot,
            'content_length' => strlen($this->page->raw_html ?? ''),
        ]);

        $systemPrompt = view('ai-prompts.analyze-page')->render();

        // Prepare content for analysis using sanitizer
        $content = $this->prepareContent($sanitizer);

        if (empty($content)) {
            Log::warning('No content available for analysis', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        // Call AI based on whether we're using screenshot
        $result = $this->useScreenshot
            ? $this->analyzeWithScreenshot($openRouter, $systemPrompt, $content)
            : $openRouter->chatJson($systemPrompt, $content);

        if ($result === null) {
            Log::error('AI analysis failed', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        // Update page with analysis results
        $this->updatePageWithResults($result);

        // Create type-specific records
        $this->createTypeSpecificRecord($result);

        Log::info('✅ AI analysis completed', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'page_type' => $result['page_type'] ?? 'unknown',
            'has_title' => !empty($result['title']),
            'has_summary' => !empty($result['summary']),
            'keywords_count' => count($result['keywords'] ?? []),
            'language' => $result['language'] ?? 'unknown',
        ]);
    }

    /**
     * Prepare content for AI analysis.
     */
    private function prepareContent(HtmlSanitizerService $sanitizer): string
    {
        $html = $this->page->raw_html;

        if (empty($html)) {
            return '';
        }

        // Use sanitizer to clean HTML and extract metadata
        return $sanitizer->getForAi($html, $this->page->url, 50000);
    }

    /**
     * Analyze page with screenshot using vision model.
     *
     * @return array<string, mixed>|null
     */
    private function analyzeWithScreenshot(OpenRouterService $openRouter, string $systemPrompt, string $content): ?array
    {
        $screenshot = $this->page->latestScreenshot;

        if ($screenshot === null) {
            Log::warning('No screenshot available, falling back to text analysis', [
                'page_id' => $this->page->id,
            ]);
            return $openRouter->chatJson($systemPrompt, $content);
        }

        $imageBase64 = $screenshot->getBase64();

        if ($imageBase64 === null) {
            Log::warning('Failed to read screenshot, falling back to text analysis', [
                'page_id' => $this->page->id,
            ]);
            return $openRouter->chatJson($systemPrompt, $content);
        }

        return $openRouter->chatWithImage($systemPrompt, $content, $imageBase64);
    }

    /**
     * Update page with AI analysis results.
     *
     * @param array<string, mixed> $result
     */
    private function updatePageWithResults(array $result): void
    {
        $this->page->update([
            'title' => $result['title'] ?? $this->page->title,
            'summary' => $result['summary'] ?? null,
            'keywords' => $result['keywords'] ?? null,
            'page_type' => $result['page_type'] ?? Page::TYPE_OTHER,
            'metadata' => [
                'depth_level' => $result['depth_level'] ?? null,
                'language' => $result['language'] ?? null,
                'analyzed_at' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Create type-specific record based on page type.
     *
     * @param array<string, mixed> $result
     */
    private function createTypeSpecificRecord(array $result): void
    {
        $pageType = $result['page_type'] ?? null;

        match ($pageType) {
            Page::TYPE_PRODUCT => $this->createProductRecord($result['product_data'] ?? []),
            Page::TYPE_ARTICLE => $this->createArticleRecord($result['article_data'] ?? []),
            Page::TYPE_CONTACT => $this->createContactRecord($result['contact_data'] ?? []),
            Page::TYPE_CATEGORY => $this->createCategoryRecord($result['category_data'] ?? []),
            default => null,
        };
    }

    /**
     * Create or update product record.
     *
     * @param array<string, mixed> $data
     */
    private function createProductRecord(array $data): void
    {
        if (empty($data) || empty($data['name'])) {
            return;
        }

        Product::updateOrCreate(
            ['page_id' => $this->page->id],
            [
                'name' => $data['name'],
                'price' => $data['price'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'description' => $data['description'] ?? null,
                'images' => $data['images'] ?? null,
                'attributes' => $data['attributes'] ?? null,
                'sku' => $data['sku'] ?? null,
                'availability' => $data['availability'] ?? null,
            ]
        );

        Log::info('Product record created/updated', [
            'page_id' => $this->page->id,
            'product_name' => $data['name'],
        ]);
    }

    /**
     * Create or update article record.
     *
     * @param array<string, mixed> $data
     */
    private function createArticleRecord(array $data): void
    {
        if (empty($data) || empty($data['title'])) {
            return;
        }

        $publishedAt = null;
        if (!empty($data['published_at'])) {
            try {
                $publishedAt = \Carbon\Carbon::parse($data['published_at']);
            } catch (\Exception $e) {
                // Ignore invalid dates
            }
        }

        Article::updateOrCreate(
            ['page_id' => $this->page->id],
            [
                'title' => $data['title'],
                'author' => $data['author'] ?? null,
                'published_at' => $publishedAt,
                'content' => $data['content'] ?? null,
                'tags' => $data['tags'] ?? null,
            ]
        );

        Log::info('Article record created/updated', [
            'page_id' => $this->page->id,
            'article_title' => $data['title'],
        ]);
    }

    /**
     * Create or update contact record.
     *
     * @param array<string, mixed> $data
     */
    private function createContactRecord(array $data): void
    {
        if (empty($data)) {
            return;
        }

        Contact::updateOrCreate(
            ['page_id' => $this->page->id],
            [
                'company_name' => $data['company_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'social_links' => $data['social_links'] ?? null,
            ]
        );

        Log::info('Contact record created/updated', [
            'page_id' => $this->page->id,
        ]);
    }

    /**
     * Create or update category record.
     *
     * @param array<string, mixed> $data
     */
    private function createCategoryRecord(array $data): void
    {
        if (empty($data) || empty($data['name'])) {
            return;
        }

        Category::updateOrCreate(
            ['page_id' => $this->page->id],
            [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'parent_category' => $data['parent_category'] ?? null,
                'products_count' => $data['products_count'] ?? null,
            ]
        );

        Log::info('Category record created/updated', [
            'page_id' => $this->page->id,
            'category_name' => $data['name'],
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('AI analysis job failed', [
            'page_id' => $this->page->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

