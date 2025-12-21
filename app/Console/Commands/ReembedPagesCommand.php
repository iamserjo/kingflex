<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-embed existing pages with the new embedding model.
 * Processes only records that already have an embedding.
 */
class ReembedPagesCommand extends Command
{
    protected $signature = 'pages:reembed
                            {--limit=1000 : Max pages to process}
                            {--domain= : Only process pages from specific domain}
                            {--chunk=25 : Batch size for embedding API}
                            {--all : Re-embed all pages with recap_content (ignore embedding state)}
                            {--include-null : Also include pages without embedding (legacy; use --all instead)}';

    protected $description = 'Recompute embeddings for pages using the configured embedding model (e.g., google/gemini-embedding-001)';

    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!$this->openRouter->isConfigured()) {
            $this->error('❌ OpenRouter is not configured. Set OPENROUTER_API_KEY in .env');
            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $domain = $this->option('domain');
        $processAll = (bool) $this->option('all') || (bool) $this->option('include-null');

        $this->info('🔄 Re-embedding pages using model: ' . config('openrouter.embedding_model'));
        $this->info("Limit: {$limit}, Chunk: {$chunkSize}" . ($domain ? ", Domain: {$domain}" : ''));

        $query = Page::query();

        if ($domain) {
            $query->whereHas('domain', fn($q) => $q->where('domain', $domain));
        }

        // Require recap_content to exist
        $query->whereNotNull('recap_content');

        if (!$processAll) {
            // Default: only records where embedding is missing
            $query->whereNull('embedding');
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn('No pages to re-embed.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} page(s) to process");

        $processed = 0;
        $errors = 0;

        $query->orderBy('id')
            ->limit($limit)
            ->chunk($chunkSize, function ($pages) use (&$processed, &$errors) {
                $texts = [];
                $pageIds = [];

                foreach ($pages as $page) {
                    $text = $this->buildText($page);
                    if (empty($text)) {
                        $this->warn("Skipped page {$page->id}: no content to embed");
                        continue;
                    }
                    $texts[] = $text;
                    $pageIds[] = $page->id;
                }

                if (empty($texts)) {
                    return;
                }

                $embeddings = $this->openRouter->createEmbeddings($texts);
                if ($embeddings === null || count($embeddings) !== count($texts)) {
                    $this->error('Embedding batch failed');
                    $errors += count($texts);
                    return;
                }

                $targetDims = (int) config('openrouter.embedding_dimensions', 2000);

                // Update pages with new embeddings
                foreach ($pageIds as $idx => $pageId) {
                    $vector = $embeddings[$idx];

                    if (count($vector) > $targetDims) {
                        $vector = array_slice($vector, 0, $targetDims);
                    }

                    $vectorString = '[' . implode(',', $vector) . ']';
                    DB::statement('UPDATE pages SET embedding = ? WHERE id = ?', [$vectorString, $pageId]);
                    $processed++;
                }

                $this->info("Batch updated: " . count($pageIds) . " pages");
            });

        $this->info("✅ Done. Processed: {$processed}, Errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Build text for embedding.
     * Prefers recap_content, then title + product_summary, then content_with_tags_purified.
     */
    private function buildText(Page $page): string
    {
        if (!empty($page->recap_content)) {
            return $page->recap_content;
        }

        $parts = [];
        if (!empty($page->title)) {
            $parts[] = $page->title;
        }
        if (!empty($page->product_summary)) {
            $parts[] = $page->product_summary;
        }
        if (!empty($page->content_with_tags_purified)) {
            $parts[] = $page->content_with_tags_purified;
        }

        $text = trim(implode("\n", $parts));

        // Keep under ~8k chars to avoid excessive token cost
        $maxLen = 8000;
        if (strlen($text) > $maxLen) {
            $text = substr($text, 0, $maxLen) . "\n... [truncated]";
        }

        return $text;
    }
}

