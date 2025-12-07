<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Page;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to generate vector embeddings for a page.
 */
class GeneratePageEmbeddingJob implements ShouldQueue
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
    ) {}

    /**
     * Execute the job.
     */
    public function handle(OpenRouterService $openRouter): void
    {
        if (!$openRouter->isConfigured()) {
            Log::warning('OpenRouter is not configured, skipping embedding generation', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        Log::info('🔢 Generating embedding for page', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'page_type' => $this->page->page_type,
            'has_title' => !empty($this->page->title),
            'has_summary' => !empty($this->page->summary),
        ]);

        // Prepare text for embedding
        $text = $this->prepareTextForEmbedding();

        if (empty($text)) {
            Log::warning('No text available for embedding', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        // Generate embedding
        $embedding = $openRouter->createEmbedding($text);

        if ($embedding === null) {
            Log::error('Failed to generate embedding', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        // Store embedding using raw SQL (pgvector requires special handling)
        $this->storePageEmbedding($embedding);

        // Also generate embeddings for type-specific records
        $this->generateTypeSpecificEmbeddings($openRouter);

        Log::info('✅ Embedding generated successfully', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
            'dimensions' => count($embedding),
            'model' => config('openrouter.embedding_model'),
        ]);
    }

    /**
     * Prepare text for embedding generation.
     */
    private function prepareTextForEmbedding(): string
    {
        $parts = [];

        // Add title
        if (!empty($this->page->title)) {
            $parts[] = "Title: {$this->page->title}";
        }

        // Add summary
        if (!empty($this->page->summary)) {
            $parts[] = "Summary: {$this->page->summary}";
        }

        // Add keywords
        if (!empty($this->page->keywords)) {
            $keywords = implode(', ', $this->page->keywords);
            $parts[] = "Keywords: {$keywords}";
        }

        // Add page type
        if (!empty($this->page->page_type)) {
            $parts[] = "Type: {$this->page->page_type}";
        }

        // Add URL for context
        $parts[] = "URL: {$this->page->url}";

        // If we don't have enough structured data, extract from HTML
        if (count($parts) < 3 && !empty($this->page->raw_html)) {
            $textContent = $this->extractTextFromHtml($this->page->raw_html);
            if (!empty($textContent)) {
                $parts[] = "Content: " . substr($textContent, 0, 5000);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Extract readable text from HTML.
     */
    private function extractTextFromHtml(string $html): string
    {
        // Remove scripts and styles
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/is', '', $html);

        // Convert to text
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Store embedding in the database using pgvector format.
     *
     * @param array<float> $embedding
     */
    private function storePageEmbedding(array $embedding): void
    {
        $embeddingString = '[' . implode(',', $embedding) . ']';

        DB::statement(
            'UPDATE pages SET embedding = ? WHERE id = ?',
            [$embeddingString, $this->page->id]
        );
    }

    /**
     * Generate embeddings for type-specific records (products, articles).
     */
    private function generateTypeSpecificEmbeddings(OpenRouterService $openRouter): void
    {
        match ($this->page->page_type) {
            Page::TYPE_PRODUCT => $this->generateProductEmbedding($openRouter),
            Page::TYPE_ARTICLE => $this->generateArticleEmbedding($openRouter),
            default => null,
        };
    }

    /**
     * Generate embedding for product record.
     */
    private function generateProductEmbedding(OpenRouterService $openRouter): void
    {
        $product = $this->page->product;

        if ($product === null) {
            return;
        }

        $text = implode("\n", array_filter([
            "Product: {$product->name}",
            $product->description ? "Description: {$product->description}" : null,
            $product->sku ? "SKU: {$product->sku}" : null,
            $product->price ? "Price: {$product->getFormattedPrice()}" : null,
        ]));

        $embedding = $openRouter->createEmbedding($text);

        if ($embedding !== null) {
            $embeddingString = '[' . implode(',', $embedding) . ']';
            DB::statement(
                'UPDATE products SET embedding = ? WHERE id = ?',
                [$embeddingString, $product->id]
            );
        }
    }

    /**
     * Generate embedding for article record.
     */
    private function generateArticleEmbedding(OpenRouterService $openRouter): void
    {
        $article = $this->page->article;

        if ($article === null) {
            return;
        }

        $text = implode("\n", array_filter([
            "Article: {$article->title}",
            $article->author ? "Author: {$article->author}" : null,
            $article->content ? "Content: " . substr($article->content, 0, 5000) : null,
            $article->tags ? "Tags: " . implode(', ', $article->tags) : null,
        ]));

        $embedding = $openRouter->createEmbedding($text);

        if ($embedding !== null) {
            $embeddingString = '[' . implode(',', $embedding) . ']';
            DB::statement(
                'UPDATE articles SET embedding = ? WHERE id = ?',
                [$embeddingString, $article->id]
            );
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Embedding generation job failed', [
            'page_id' => $this->page->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

