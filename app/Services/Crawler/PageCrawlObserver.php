<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Events\HtmlDomReady;
use App\Events\PageRawHtmlFetched;
use App\Models\Domain;
use App\Models\Page;
use App\Models\PageLink;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Spatie\Crawler\CrawlObservers\CrawlObserver;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Observer for handling crawled pages from spatie/crawler.
 */
class PageCrawlObserver extends CrawlObserver
{
    private Domain $domain;
    private int $currentDepth = 0;

    /** @var array<string, int> Map of URL hashes to page IDs for link tracking */
    private array $urlToPageId = [];

    public function __construct(Domain $domain)
    {
        $this->domain = $domain;
    }

    /**
     * Called when the crawler will crawl the url.
     */
    public function willCrawl(UriInterface $url, ?string $linkText): void
    {
        Log::debug('Will crawl URL', ['url' => (string) $url]);
    }

    /**
     * Called when the crawler has crawled the given url successfully.
     */
    public function crawled(
        UriInterface $url,
        ResponseInterface $response,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null,
    ): void {
        $urlString = (string) $url;
        $statusCode = $response->getStatusCode();

        // Only process successful responses
        if ($statusCode < 200 || $statusCode >= 300) {
            Log::warning('Non-success status code', [
                'url' => $urlString,
                'status' => $statusCode,
            ]);
            return;
        }

        $body = (string) $response->getBody();
        $headers = $this->flattenHeaders($response->getHeaders());

        // Calculate depth
        $depth = $this->calculateDepth($foundOnUrl);

        // Create or update page
        $page = $this->createOrUpdatePage($urlString, $body, $depth);

        if ($page === null) {
            return;
        }

        // Track URL to page ID mapping
        $this->urlToPageId[$page->url_hash] = $page->id;

        // Create link relationship if found on another URL
        if ($foundOnUrl !== null) {
            $this->createPageLink($foundOnUrl, $page, $linkText);
        }

        // Dispatch PageRawHtmlFetched event
        PageRawHtmlFetched::dispatch($page, $body, $statusCode, $headers);

        // Extract links from the page
        $extractedLinks = $this->extractLinks($body, $urlString);

        // Process extracted links - create page records or increment inbound link counts
        $this->processExtractedLinks($extractedLinks, $page);

        // Dispatch HtmlDomReady event (for raw HTML, not JS-rendered)
        HtmlDomReady::dispatch($page, $body, false, $extractedLinks);

        Log::info('✅ Page crawled successfully', [
            'url' => $urlString,
            'page_id' => $page->id,
            'depth' => $depth,
            'status_code' => $statusCode,
            'content_length' => strlen($body),
            'links_found' => count($extractedLinks),
            'links_internal' => count(array_filter($extractedLinks, fn($link) => $this->domain->isUrlAllowed($link))),
            'inbound_links_count' => $page->inbound_links_count,
        ]);
    }

    /**
     * Called when the crawler had a problem crawling the given url.
     */
    public function crawlFailed(
        UriInterface $url,
        RequestException $requestException,
        ?UriInterface $foundOnUrl = null,
        ?string $linkText = null,
    ): void {
        Log::error('Crawl failed', [
            'url' => (string) $url,
            'error' => $requestException->getMessage(),
            'found_on' => $foundOnUrl ? (string) $foundOnUrl : null,
        ]);
    }

    /**
     * Called when the crawl has ended.
     */
    public function finishedCrawling(): void
    {
        // Update domain's last_crawled_at
        $this->domain->update(['last_crawled_at' => now()]);

        // Update inbound links count for all crawled pages
        $this->updateInboundLinksCounts();

        $totalPages = $this->domain->pages()->count();
        $queuedPages = $this->domain->pages()->whereNull('last_crawled_at')->count();
        $processedPages = count($this->urlToPageId);

        Log::info('🏁 Finished crawling domain', [
            'domain' => $this->domain->domain,
            'pages_crawled_this_session' => $processedPages,
            'total_pages' => $totalPages,
            'queued_pages' => $queuedPages,
        ]);
    }

    /**
     * Create or update a page record.
     */
    private function createOrUpdatePage(string $url, string $html, int $depth): ?Page
    {
        $urlHash = hash('sha256', $url);

        try {
            return DB::transaction(function () use ($url, $urlHash, $html, $depth) {
                $page = Page::updateOrCreate(
                    [
                        'domain_id' => $this->domain->id,
                        'url_hash' => $urlHash,
                    ],
                    [
                        'url' => $url,
                        'raw_html' => $html,
                        'depth' => $depth,
                        'last_crawled_at' => now(),
                    ]
                );

                return $page;
            });
        } catch (\Exception $e) {
            Log::error('Failed to create/update page', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a link relationship between pages.
     */
    private function createPageLink(UriInterface $sourceUrl, Page $targetPage, ?string $anchorText): void
    {
        $sourceUrlHash = hash('sha256', (string) $sourceUrl);

        // Find source page ID
        $sourcePageId = $this->urlToPageId[$sourceUrlHash] ?? null;

        if ($sourcePageId === null) {
            // Source page might not be crawled yet, try to find it in DB
            $sourcePage = Page::where('domain_id', $this->domain->id)
                ->where('url_hash', $sourceUrlHash)
                ->first();

            if ($sourcePage === null) {
                return;
            }

            $sourcePageId = $sourcePage->id;
        }

        try {
            PageLink::updateOrCreate(
                [
                    'source_page_id' => $sourcePageId,
                    'target_page_id' => $targetPage->id,
                ],
                [
                    'anchor_text' => $anchorText,
                ]
            );
        } catch (\Exception $e) {
            // Ignore duplicate key errors
            Log::debug('Could not create page link', [
                'source_id' => $sourcePageId,
                'target_id' => $targetPage->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate the depth of a URL based on where it was found.
     */
    private function calculateDepth(?UriInterface $foundOnUrl): int
    {
        if ($foundOnUrl === null) {
            return 0; // Starting URL
        }

        $foundOnHash = hash('sha256', (string) $foundOnUrl);

        // Find the parent page to get its depth
        $parentPage = Page::where('domain_id', $this->domain->id)
            ->where('url_hash', $foundOnHash)
            ->first();

        return $parentPage ? $parentPage->depth + 1 : 1;
    }

    /**
     * Extract links from HTML content.
     *
     * @return array<string>
     */
    private function extractLinks(string $html, string $baseUrl): array
    {
        $links = [];

        try {
            $crawler = new Crawler($html, $baseUrl);

            $crawler->filter('a[href]')->each(function (Crawler $node) use (&$links, $baseUrl) {
                $href = $node->attr('href');

                if ($href === null || $href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                    return;
                }

                // Resolve relative URLs
                $absoluteUrl = $this->resolveUrl($href, $baseUrl);

                if ($absoluteUrl !== null) {
                    $links[] = $absoluteUrl;
                }
            });
        } catch (\Exception $e) {
            Log::warning('Failed to extract links', [
                'url' => $baseUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return array_unique($links);
    }

    /**
     * Resolve a relative URL to an absolute URL.
     */
    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        // Already absolute
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $parsed = parse_url($baseUrl);

        if ($parsed === false || !isset($parsed['host'])) {
            return null;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        // Protocol-relative URL
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        // Absolute path
        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$port}{$href}";
        }

        // Relative path
        $path = $parsed['path'] ?? '/';
        $basePath = dirname($path);

        if ($basePath === '.' || $basePath === '/') {
            $basePath = '';
        }

        return "{$scheme}://{$host}{$port}{$basePath}/{$href}";
    }

    /**
     * Update inbound links count for all pages.
     */
    private function updateInboundLinksCounts(): void
    {
        // Update counts using a single query
        DB::statement('
            UPDATE pages
            SET inbound_links_count = (
                SELECT COUNT(*)
                FROM page_links
                WHERE page_links.target_page_id = pages.id
            )
            WHERE domain_id = ?
        ', [$this->domain->id]);
    }

    /**
     * Process extracted links - create page records or update inbound link counts.
     *
     * @param array<string> $links
     * @param Page $sourcePage
     */
    private function processExtractedLinks(array $links, Page $sourcePage): void
    {
        foreach ($links as $link) {
            // Only process links from this domain
            if (!$this->domain->isUrlAllowed($link)) {
                continue;
            }

            $linkHash = hash('sha256', $link);

            // Check if page already exists
            $existingPage = Page::where('domain_id', $this->domain->id)
                ->where('url_hash', $linkHash)
                ->first();

            if ($existingPage) {
                // Page exists - just create/update the link relationship
                $this->createPageLink(
                    \GuzzleHttp\Psr7\Utils::uriFor($sourcePage->url),
                    $existingPage,
                    null
                );
            } else {
                // New page - create record with last_crawled_at = null (to be processed later)
                try {
                    $newPage = Page::create([
                        'domain_id' => $this->domain->id,
                        'url' => $link,
                        'url_hash' => $linkHash,
                        'depth' => $sourcePage->depth + 1,
                        'last_crawled_at' => null, // Mark as unprocessed
                    ]);

                    // Create link relationship
                    PageLink::create([
                        'source_page_id' => $sourcePage->id,
                        'target_page_id' => $newPage->id,
                        'anchor_text' => null,
                    ]);

                    Log::debug('Created new page record from link', [
                        'url' => $link,
                        'page_id' => $newPage->id,
                        'source_page_id' => $sourcePage->id,
                    ]);
                } catch (\Exception $e) {
                    // Ignore duplicate key errors (race condition)
                    Log::debug('Could not create page from link', [
                        'url' => $link,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Flatten response headers.
     *
     * @param array<string, array<string>> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }
}

