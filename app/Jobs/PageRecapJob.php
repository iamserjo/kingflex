<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Page;
use App\Services\Html\HtmlSanitizerService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to create a one-sentence recap of a page using AI.
 * The recap is used for embedding generation and semantic search.
 */
class PageRecapJob implements ShouldQueue
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
    public function handle(OpenRouterService $openRouter, HtmlSanitizerService $sanitizer): void
    {
        if (!$openRouter->isConfigured()) {
            Log::warning('OpenRouter not configured, skipping page recap', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        Log::info('📝 Creating page recap', [
            'page_id' => $this->page->id,
            'url' => $this->page->url,
        ]);

        // Get sanitized content
        $content = $sanitizer->getForAi($this->page->raw_html ?? '', $this->page->url, 50000);

        if (empty($content)) {
            Log::warning('No content available for recap', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        // Get system prompt
        $systemPrompt = view('ai-prompts.page-recap')->render();

        // Request recap from AI (plain text, not JSON)
        $response = $openRouter->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $content],
        ]);

        if ($response === null || empty($response['content'])) {
            Log::error('❌ Page recap generation failed', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        // Clean up the recap (remove quotes, extra whitespace)
        $recap = trim($response['content']);
        $recap = trim($recap, '"\'');

        // Save recap to page
        $this->page->update(['recap_content' => $recap]);

        Log::info('✅ Page recap created', [
            'page_id' => $this->page->id,
            'recap' => $recap,
        ]);

        // Generate embedding for the recap
        $this->generateRecapEmbedding($openRouter, $recap);
    }

    /**
     * Generate and save embedding for the recap.
     */
    private function generateRecapEmbedding(OpenRouterService $openRouter, string $recap): void
    {
        Log::info('🔢 Generating recap embedding', [
            'page_id' => $this->page->id,
        ]);

        $embedding = $openRouter->createEmbedding($recap);

        if ($embedding === null) {
            Log::error('❌ Recap embedding generation failed', [
                'page_id' => $this->page->id,
            ]);
            return;
        }

        // Save embedding to database
        $embeddingString = '[' . implode(',', $embedding) . ']';

        DB::statement(
            'UPDATE pages SET embedding = ? WHERE id = ?',
            [$embeddingString, $this->page->id]
        );

        Log::info('✅ Recap embedding saved', [
            'page_id' => $this->page->id,
            'dimensions' => count($embedding),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Page recap job failed', [
            'page_id' => $this->page->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

