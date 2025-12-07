<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Events\HtmlDomReady;
use App\Events\PageRendered;
use App\Models\Domain;
use App\Models\Page;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;

/**
 * Service for web crawling and page rendering.
 */
class WebCrawlerService
{
    /**
     * Crawl a domain starting from its base URL.
     * For new domains, only crawls the homepage to discover initial links.
     */
    public function crawlDomain(Domain $domain, ?int $maxPages = null): void
    {
        $observer = new PageCrawlObserver($domain);
        $baseUrl = $domain->getBaseUrl();

        Log::info('Starting domain crawl', [
            'domain' => $domain->domain,
            'base_url' => $baseUrl,
        ]);

        // For new domains (no pages yet), only crawl the homepage to discover links
        // Subsequent pages will be processed by crawl:update command
        $crawler = Crawler::create()
            ->setCrawlObserver($observer)
            ->setUserAgent(config('crawler.user_agent'))
            ->setMaximumResponseSize(config('crawler.max_response_size'))
            ->setParseableMimeTypes(config('crawler.parseable_mime_types'))
            ->setTotalCrawlLimit(1) // Only crawl homepage
            ->setMaximumDepth(0); // Don't follow links automatically

        // Respect robots.txt if configured
        if (config('crawler.respect_robots')) {
            $crawler->respectRobots();
        }

        $crawler->startCrawling($baseUrl);

        Log::info('Domain homepage crawled, links extracted and queued', [
            'domain' => $domain->domain,
            'pages_queued' => $domain->pages()->whereNull('last_crawled_at')->count(),
        ]);
    }

    /**
     * Crawl a single page and update its content.
     */
    public function crawlPage(Page $page): void
    {
        Log::info('Crawling single page', ['url' => $page->url]);

        $domain = $page->domain;
        $observer = new PageCrawlObserver($domain);

        $crawler = Crawler::create()
            ->setCrawlObserver($observer)
            ->setUserAgent(config('crawler.user_agent'))
            ->setMaximumResponseSize(config('crawler.max_response_size'))
            ->setTotalCrawlLimit(1)
            ->setMaximumDepth(0); // Only crawl this exact page

        $crawler->startCrawling($page->url);
    }

    /**
     * Render a page with JavaScript using Browsershot (Puppeteer).
     */
    public function renderPageWithJs(Page $page, bool $takeScreenshot = true): void
    {
        Log::info('Rendering page with JavaScript', ['url' => $page->url]);

        try {
            $browsershot = $this->createBrowsershot($page->url);

            // Get rendered HTML
            $renderedHtml = $browsershot->bodyHtml();

            // Update page with rendered HTML
            $page->update([
                'raw_html' => $renderedHtml,
                'last_crawled_at' => now(),
            ]);

            $screenshotPath = null;

            // Take screenshot if requested
            if ($takeScreenshot) {
                $screenshotPath = $this->takeScreenshot($page);
            }

            // Dispatch PageRendered event
            PageRendered::dispatch($page, $renderedHtml, $screenshotPath, [
                'loadTime' => null, // Browsershot doesn't provide this directly
            ]);

            // Extract links and dispatch HtmlDomReady
            $extractedLinks = $this->extractLinksFromHtml($renderedHtml, $page->url);
            HtmlDomReady::dispatch($page, $renderedHtml, true, $extractedLinks);

            Log::info('Page rendered successfully', [
                'url' => $page->url,
                'screenshot' => $screenshotPath !== null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to render page with JavaScript', [
                'url' => $page->url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Take a screenshot of a page.
     */
    public function takeScreenshot(Page $page): ?string
    {
        Log::info('📸 Starting screenshot capture', [
            'page_id' => $page->id,
            'url' => $page->url,
        ]);

        try {
            // Configure screenshot settings
            $format = config('crawler.screenshot_format', 'png');
            $quality = config('crawler.screenshot_quality', 90);
            $fullPage = config('crawler.screenshot_full_page', true);

            // Generate filename
            $filename = sprintf(
                '%s/%d_%s.%s',
                config('crawler.screenshots_path'),
                $page->id,
                now()->format('Y-m-d_His'),
                $format
            );

            $fullPath = storage_path('app/' . $filename);

            // Ensure directory exists
            $directory = dirname($fullPath);
            if (!is_dir($directory)) {
                Log::debug('Creating screenshot directory', ['path' => $directory]);
                mkdir($directory, 0755, true);
            }

            Log::debug('Creating Browsershot instance', ['url' => $page->url]);
            $browsershot = $this->createBrowsershot($page->url);

            // Take screenshot
            if ($fullPage) {
                $browsershot->fullPage();
            }

            if ($format === 'jpeg') {
                $browsershot->setScreenshotType('jpeg', $quality);
            }

            Log::debug('Saving screenshot', ['path' => $fullPath]);
            $browsershot->save($fullPath);

            // Verify file was created
            if (!file_exists($fullPath)) {
                Log::error('Screenshot file was not created', ['path' => $fullPath]);
                return null;
            }

            // Get image dimensions
            $imageInfo = @getimagesize($fullPath);
            $width = $imageInfo[0] ?? null;
            $height = $imageInfo[1] ?? null;
            $fileSize = @filesize($fullPath);

            Log::debug('Screenshot file info', [
                'width' => $width,
                'height' => $height,
                'file_size' => $fileSize,
            ]);

            // Create screenshot record
            $screenshot = $page->screenshots()->create([
                'path' => $filename,
                'format' => $format,
                'width' => $width,
                'height' => $height,
                'file_size' => $fileSize ?: null,
            ]);

            Log::info('✅ Screenshot saved successfully', [
                'page_id' => $page->id,
                'screenshot_id' => $screenshot->id,
                'path' => $filename,
                'size' => $fileSize,
            ]);

            return $filename;
        } catch (\Exception $e) {
            Log::error('❌ Failed to take screenshot', [
                'page_id' => $page->id,
                'url' => $page->url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Create a configured Browsershot instance.
     */
    private function createBrowsershot(string $url): Browsershot
    {
        $chromePath = config('crawler.chrome_path');
        $nodePath = config('crawler.node_path');
        $npmPath = config('crawler.npm_path');

        Log::debug('🔧 Creating Browsershot instance', [
            'url' => $url,
            'chrome_path' => $chromePath ?: 'auto-detect',
            'node_path' => $nodePath ?: 'auto-detect',
            'npm_path' => $npmPath ?: 'auto-detect',
            'viewport' => config('crawler.viewport_width') . 'x' . config('crawler.viewport_height'),
            'timeout' => config('crawler.timeout') . 's',
        ]);

        $browsershot = Browsershot::url($url)
            ->setOption('args', config('crawler.puppeteer_args'))
            ->waitUntilNetworkIdle()
            ->windowSize(
                config('crawler.viewport_width'),
                config('crawler.viewport_height')
            )
            ->timeout(config('crawler.timeout') * 1000); // Convert to milliseconds

        // Set custom Chrome path if configured
        if ($chromePath) {
            $browsershot->setChromePath($chromePath);
        }

        // Set custom Node path if configured
        $nodePath = config('crawler.node_path');
        if ($nodePath) {
            $browsershot->setNodeBinary($nodePath);
        }

        // Set custom NPM path if configured
        $npmPath = config('crawler.npm_path');
        if ($npmPath) {
            $browsershot->setNpmBinary($npmPath);
        }

        return $browsershot;
    }

    /**
     * Extract links from HTML content.
     *
     * @return array<string>
     */
    private function extractLinksFromHtml(string $html, string $baseUrl): array
    {
        $links = [];

        try {
            $crawler = new \Symfony\Component\DomCrawler\Crawler($html, $baseUrl);

            $crawler->filter('a[href]')->each(function ($node) use (&$links, $baseUrl) {
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
}

