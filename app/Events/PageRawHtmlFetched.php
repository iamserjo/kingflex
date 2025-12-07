<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Page;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when raw HTML is fetched via Guzzle (without JavaScript rendering).
 */
class PageRawHtmlFetched
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Page $page The page model with raw HTML content
     * @param string $rawHtml The raw HTML content fetched
     * @param int $statusCode HTTP response status code
     * @param array<string, string> $headers HTTP response headers
     */
    public function __construct(
        public Page $page,
        public string $rawHtml,
        public int $statusCode = 200,
        public array $headers = [],
    ) {}

    /**
     * Get the URL of the fetched page.
     */
    public function getUrl(): string
    {
        return $this->page->url;
    }

    /**
     * Check if the fetch was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Get content type from headers.
     */
    public function getContentType(): ?string
    {
        return $this->headers['content-type'] ?? $this->headers['Content-Type'] ?? null;
    }
}

