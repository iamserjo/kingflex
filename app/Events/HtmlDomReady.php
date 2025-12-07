<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Page;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when the DOM is fully loaded and ready for processing.
 * This is the final event in the crawling pipeline, indicating that the page
 * is ready for AI analysis and data extraction.
 */
class HtmlDomReady
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Page $page The page model with updated content
     * @param string $html The final HTML content (raw or rendered)
     * @param bool $wasJsRendered Whether the page was JavaScript-rendered
     * @param array<string, string> $extractedLinks Links found on the page
     */
    public function __construct(
        public Page $page,
        public string $html,
        public bool $wasJsRendered = false,
        public array $extractedLinks = [],
    ) {}

    /**
     * Get the URL of the page.
     */
    public function getUrl(): string
    {
        return $this->page->url;
    }

    /**
     * Get the number of links extracted from the page.
     */
    public function getLinkCount(): int
    {
        return count($this->extractedLinks);
    }

    /**
     * Check if this page was rendered with JavaScript.
     */
    public function wasRenderedWithJs(): bool
    {
        return $this->wasJsRendered;
    }

    /**
     * Get internal links (same domain).
     *
     * @return array<string>
     */
    public function getInternalLinks(): array
    {
        $domain = $this->page->domain;

        return array_filter($this->extractedLinks, function ($link) use ($domain) {
            return $domain->isUrlAllowed($link);
        });
    }

    /**
     * Get external links (different domain).
     *
     * @return array<string>
     */
    public function getExternalLinks(): array
    {
        $domain = $this->page->domain;

        return array_filter($this->extractedLinks, function ($link) use ($domain) {
            return !$domain->isUrlAllowed($link);
        });
    }
}

