<?php

declare(strict_types=1);

namespace App\Services\Playwright;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Service for extracting rendered page content using Playwright.
 * Renders pages with JavaScript and extracts semantically structured HTML.
 */
class ContentExtractorService
{
    /**
     * Default timeout for page loading (ms).
     */
    private const DEFAULT_TIMEOUT = 30000;

    /**
     * Default wait strategy.
     */
    private const DEFAULT_WAIT_FOR = 'networkidle';

    /**
     * Extract content from a URL using Playwright headless browser.
     *
     * @param string $url The URL to extract content from
     * @param int $timeout Page load timeout in milliseconds
     * @param string $waitFor Wait until event (load, domcontentloaded, networkidle, commit)
     * @return array{success: bool, content: ?string, title: ?string, description: ?string, error: ?string, loadTimeMs: ?int}
     */
    public function extract(
        string $url,
        int $timeout = self::DEFAULT_TIMEOUT,
        string $waitFor = self::DEFAULT_WAIT_FOR,
    ): array {
        $startTime = microtime(true);

        Log::info('🎭 [Playwright] Starting content extraction', [
            'url' => $url,
            'timeout' => $timeout,
            'waitFor' => $waitFor,
        ]);

        $scriptPath = base_path('scripts/puppeteer-extract-text.js');

        if (!file_exists($scriptPath)) {
            Log::error('🎭 [Playwright] Script not found', [
                'path' => $scriptPath,
                'url' => $url,
            ]);
            return [
                'success' => false,
                'content' => null,
                'title' => null,
                'description' => null,
                'error' => 'Playwright script not found',
                'loadTimeMs' => null,
            ];
        }

        $args = [
            'node',
            $scriptPath,
            $url,
            "--timeout={$timeout}",
            "--wait-for={$waitFor}",
            '--json',
        ];

        Log::debug('🎭 [Playwright] Executing node process', [
            'url' => $url,
            'command' => implode(' ', $args),
        ]);

        $process = new Process($args);
        $processTimeout = (int) ($timeout / 1000) + 60;
        $process->setTimeout($processTimeout);

        Log::debug('🎭 [Playwright] Process timeout set', [
            'url' => $url,
            'processTimeoutSec' => $processTimeout,
        ]);

        try {
            Log::debug('🎭 [Playwright] Running browser...', ['url' => $url]);
            $process->run();
            $processTime = round((microtime(true) - $startTime) * 1000);

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                $exitCode = $process->getExitCode();

                Log::error('🎭 [Playwright] Process failed', [
                    'url' => $url,
                    'exitCode' => $exitCode,
                    'errorOutput' => substr($errorOutput, 0, 1000),
                    'processTimeMs' => $processTime,
                ]);

                return [
                    'success' => false,
                    'content' => null,
                    'title' => null,
                    'description' => null,
                    'error' => $errorOutput ?: 'Process failed with exit code ' . $exitCode,
                    'loadTimeMs' => null,
                ];
            }

            Log::debug('🎭 [Playwright] Process completed successfully', [
                'url' => $url,
                'processTimeMs' => $processTime,
            ]);

            $output = $process->getOutput();
            $outputLength = strlen($output);

            Log::debug('🎭 [Playwright] Parsing JSON output', [
                'url' => $url,
                'outputLength' => $outputLength,
            ]);

            $data = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('🎭 [Playwright] JSON parse error', [
                    'url' => $url,
                    'jsonError' => json_last_error_msg(),
                    'outputPreview' => substr($output, 0, 500),
                    'processTimeMs' => $processTime,
                ]);

                return [
                    'success' => false,
                    'content' => null,
                    'title' => null,
                    'description' => null,
                    'error' => 'Failed to parse JSON output: ' . json_last_error_msg(),
                    'loadTimeMs' => null,
                ];
            }

            // Check for error in response
            if (isset($data['error'])) {
                Log::error('🎭 [Playwright] Browser returned error', [
                    'url' => $url,
                    'error' => $data['error'],
                    'errorType' => $data['errorType'] ?? 'unknown',
                    'processTimeMs' => $processTime,
                ]);

                return [
                    'success' => false,
                    'content' => null,
                    'title' => null,
                    'description' => null,
                    'error' => $data['error'],
                    'loadTimeMs' => null,
                ];
            }

            $contentLength = $data['contentLength'] ?? strlen($data['content'] ?? '');
            $pageLoadTime = $data['loadTimeMs'] ?? null;
            $totalTime = round((microtime(true) - $startTime) * 1000);

            Log::info('🎭 [Playwright] ✅ Content extracted successfully', [
                'url' => $url,
                'title' => $data['title'] ?? null,
                'hasDescription' => !empty($data['metaDescription']),
                'contentLength' => $contentLength,
                'pageLoadTimeMs' => $pageLoadTime,
                'totalProcessTimeMs' => $totalTime,
                'status' => $data['status'] ?? null,
            ]);

            return [
                'success' => true,
                'content' => $data['content'] ?? null,
                'title' => $data['title'] ?? null,
                'description' => $data['metaDescription'] ?? null,
                'error' => null,
                'loadTimeMs' => $pageLoadTime,
            ];

        } catch (\Throwable $e) {
            $errorTime = round((microtime(true) - $startTime) * 1000);

            Log::error('🎭 [Playwright] Exception during extraction', [
                'url' => $url,
                'exception' => $e->getMessage(),
                'exceptionClass' => get_class($e),
                'processTimeMs' => $errorTime,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'content' => null,
                'title' => null,
                'description' => null,
                'error' => $e->getMessage(),
                'loadTimeMs' => null,
            ];
        }
    }

    /**
     * Extract content and format it for AI consumption.
     *
     * @param string $url The URL to extract content from
     * @param int $maxLength Maximum content length (0 = unlimited)
     * @param int $timeout Page load timeout in milliseconds
     * @return string Formatted content for AI, or empty string on failure
     */
    public function getForAi(
        string $url,
        int $maxLength = 50000,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): string {
        Log::debug('🎭 [Playwright] getForAi called', [
            'url' => $url,
            'maxLength' => $maxLength,
            'timeout' => $timeout,
        ]);

        $result = $this->extract($url, $timeout);

        if (!$result['success'] || empty($result['content'])) {
            Log::warning('🎭 [Playwright] getForAi returning empty - extraction failed', [
                'url' => $url,
                'success' => $result['success'],
                'hasContent' => !empty($result['content']),
                'error' => $result['error'] ?? null,
            ]);
            return '';
        }

        $parts = [];

        // Add URL
        $parts[] = "URL: {$url}";

        // Add metadata
        if ($result['title']) {
            $parts[] = "Title: {$result['title']}";
        }

        if ($result['description']) {
            $parts[] = "Description: {$result['description']}";
        }

        // Add content
        $parts[] = "\n=== RENDERED CONTENT ===";

        $content = $result['content'];
        $originalLength = strlen($content);
        $wasTruncated = false;

        // Truncate if needed
        if ($maxLength > 0 && strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength) . "\n... [truncated]";
            $wasTruncated = true;
        }

        $parts[] = $content;

        $formattedContent = implode("\n", $parts);

        Log::debug('🎭 [Playwright] getForAi formatted content', [
            'url' => $url,
            'originalContentLength' => $originalLength,
            'wasTruncated' => $wasTruncated,
            'finalLength' => strlen($formattedContent),
        ]);

        return $formattedContent;
    }
}
