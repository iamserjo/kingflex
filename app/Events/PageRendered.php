<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Page;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a page is rendered via Puppeteer (with JavaScript execution).
 */
class PageRendered
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Page $page The page model
     * @param string $renderedHtml The fully rendered HTML after JavaScript execution
     * @param string|null $screenshotPath Path to the captured screenshot
     * @param array<string, mixed> $browserMetrics Browser performance metrics
     */
    public function __construct(
        public Page $page,
        public string $renderedHtml,
        public ?string $screenshotPath = null,
        public array $browserMetrics = [],
    ) {}

    /**
     * Get the URL of the rendered page.
     */
    public function getUrl(): string
    {
        return $this->page->url;
    }

    /**
     * Check if a screenshot was captured.
     */
    public function hasScreenshot(): bool
    {
        return $this->screenshotPath !== null;
    }

    /**
     * Get the page load time if available.
     */
    public function getLoadTimeMs(): ?int
    {
        return $this->browserMetrics['loadTime'] ?? null;
    }
}

