<?php

declare(strict_types=1);

namespace App\Services\Html;

use Illuminate\Support\Facades\Log;

/**
 * Service for sanitizing HTML before sending to AI.
 * Removes non-visible content while preserving important metadata.
 */
class HtmlSanitizerService
{
    /**
     * Sanitize HTML for AI analysis.
     * Removes scripts, styles, comments, and other non-visible content.
     * Preserves important metadata and semantic structure.
     *
     * @param string $html Raw HTML content
     * @param int $maxLength Maximum length of output (0 = unlimited)
     * @return array{html: string, metadata: array}
     */
    public function sanitize(string $html, int $maxLength = 50000): array
    {
        // Extract metadata before cleaning
        $metadata = $this->extractMetadata($html);

        // Clean the HTML
        $cleanHtml = $this->cleanHtml($html);

        // Truncate if needed
        if ($maxLength > 0 && strlen($cleanHtml) > $maxLength) {
            $cleanHtml = substr($cleanHtml, 0, $maxLength) . "\n... [truncated]";
        }

        return [
            'html' => $cleanHtml,
            'metadata' => $metadata,
        ];
    }

    /**
     * Get sanitized HTML as formatted string for AI.
     *
     * @param string $html Raw HTML
     * @param string $url Page URL
     * @param int $maxLength Maximum length
     * @return string Formatted content for AI
     */
    public function getForAi(string $html, string $url, int $maxLength = 50000): string
    {
        $result = $this->sanitize($html, $maxLength);

        $parts = [];

        // Add URL
        $parts[] = "URL: {$url}";

        // Add extracted metadata
        if (!empty($result['metadata'])) {
            $parts[] = "\n=== METADATA ===";

            if (!empty($result['metadata']['title'])) {
                $parts[] = "Title: {$result['metadata']['title']}";
            }

            if (!empty($result['metadata']['description'])) {
                $parts[] = "Description: {$result['metadata']['description']}";
            }

            if (!empty($result['metadata']['keywords'])) {
                $parts[] = "Keywords: {$result['metadata']['keywords']}";
            }

            if (!empty($result['metadata']['og'])) {
                foreach ($result['metadata']['og'] as $key => $value) {
                    $parts[] = "OG:{$key}: {$value}";
                }
            }

            if (!empty($result['metadata']['canonical'])) {
                $parts[] = "Canonical: {$result['metadata']['canonical']}";
            }

            if (!empty($result['metadata']['language'])) {
                $parts[] = "Language: {$result['metadata']['language']}";
            }
        }

        // Add cleaned HTML
        $parts[] = "\n=== HTML CONTENT ===";
        $parts[] = $result['html'];

        return implode("\n", $parts);
    }

    /**
     * Extract important metadata from HTML.
     *
     * @param string $html
     * @return array<string, mixed>
     */
    public function extractMetadata(string $html): array
    {
        $metadata = [];

        // Extract title
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $metadata['title'] = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        }

        // Extract meta description
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['description'] = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', $html, $matches)) {
            $metadata['description'] = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        }

        // Extract meta keywords
        if (preg_match('/<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['keywords'] = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        }

        // Extract Open Graph tags
        $ogTags = [];
        preg_match_all('/<meta[^>]+property=["\']og:([^"\']+)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $ogTags[$match[1]] = trim(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
        }
        // Also check reverse order
        preg_match_all('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:([^"\']+)["\']/i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $ogTags[$match[2]] = trim(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($ogTags)) {
            $metadata['og'] = $ogTags;
        }

        // Extract canonical URL
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['canonical'] = $matches[1];
        }

        // Extract language
        if (preg_match('/<html[^>]+lang=["\']([^"\']+)["\']/i', $html, $matches)) {
            $metadata['language'] = $matches[1];
        }

        // Extract JSON-LD structured data
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);
        if (!empty($matches[1])) {
            $jsonLd = [];
            foreach ($matches[1] as $json) {
                $decoded = json_decode(trim($json), true);
                if ($decoded) {
                    $jsonLd[] = $decoded;
                }
            }
            if (!empty($jsonLd)) {
                $metadata['json_ld'] = $jsonLd;
            }
        }

        return $metadata;
    }

    /**
     * Clean HTML by removing non-visible content.
     *
     * @param string $html
     * @return string
     */
    public function cleanHtml(string $html): string
    {
        // First, fix UTF-8 encoding issues
        $html = $this->sanitizeUtf8($html);
        // Remove HTML comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Remove script tags and content
        $html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is', '', $html);

        // Remove noscript tags and content
        $html = preg_replace('/<noscript\b[^<]*(?:(?!<\/noscript>)<[^<]*)*<\/noscript>/is', '', $html);

        // Remove style tags and content
        $html = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/is', '', $html);

        // Remove template tags (Vue, Angular, etc.)
        $html = preg_replace('/<template\b[^<]*(?:(?!<\/template>)<[^<]*)*<\/template>/is', '', $html);

        // Remove SVG tags (usually decorative)
        $html = preg_replace('/<svg\b[^<]*(?:(?!<\/svg>)<[^<]*)*<\/svg>/is', '[SVG]', $html);

        // Remove iframe tags
        $html = preg_replace('/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/is', '[IFRAME]', $html);

        // Remove object/embed tags
        $html = preg_replace('/<object\b[^<]*(?:(?!<\/object>)<[^<]*)*<\/object>/is', '', $html);
        $html = preg_replace('/<embed[^>]*>/is', '', $html);

        // Remove base64 images (keep src attribute structure)
        $html = preg_replace('/src=["\']data:image\/[^"\']+["\']/i', 'src="[BASE64_IMAGE]"', $html);

        // Remove inline styles
        $html = preg_replace('/\s+style=["\'][^"\']*["\']/i', '', $html);

        // Remove class attributes (optional, can be useful for AI)
        // $html = preg_replace('/\s+class=["\'][^"\']*["\']/i', '', $html);

        // Remove data-* attributes
        $html = preg_replace('/\s+data-[a-z0-9-]+=["\'][^"\']*["\']/i', '', $html);

        // Remove on* event handlers
        $html = preg_replace('/\s+on[a-z]+=["\'][^"\']*["\']/i', '', $html);

        // Remove hidden elements
        $html = preg_replace('/<[^>]+hidden[^>]*>.*?<\/[^>]+>/is', '', $html);

        // Remove aria-* attributes (accessibility, not needed for content)
        $html = preg_replace('/\s+aria-[a-z-]+=["\'][^"\']*["\']/i', '', $html);

        // Remove role attributes
        $html = preg_replace('/\s+role=["\'][^"\']*["\']/i', '', $html);

        // Normalize whitespace
        $html = preg_replace('/\s+/', ' ', $html);

        // Remove empty tags
        $html = preg_replace('/<(\w+)[^>]*>\s*<\/\1>/i', '', $html);

        // Add newlines after block elements for readability
        $blockElements = ['div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'tr', 'section', 'article', 'header', 'footer', 'nav', 'main', 'aside'];
        foreach ($blockElements as $tag) {
            $html = preg_replace('/<\/' . $tag . '>/i', "</{$tag}>\n", $html);
        }

        // Trim and clean up multiple newlines
        $html = preg_replace('/\n\s*\n/', "\n", $html);
        $html = trim($html);

        return $html;
    }

    /**
     * Get just the visible text content from HTML.
     *
     * @param string $html
     * @return string
     */
    public function extractText(string $html): string
    {
        $cleaned = $this->cleanHtml($html);
        $text = strip_tags($cleaned);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Sanitize UTF-8 string, removing invalid characters.
     *
     * @param string $string
     * @return string
     */
    public function sanitizeUtf8(string $string): string
    {
        // Remove NULL bytes
        $string = str_replace("\0", '', $string);

        // Try to detect and convert encoding
        $encoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1251', 'Windows-1252', 'KOI8-R'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $string = mb_convert_encoding($string, 'UTF-8', $encoding);
        }

        // Remove invalid UTF-8 sequences
        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');

        // Use iconv to remove invalid characters
        $string = @iconv('UTF-8', 'UTF-8//IGNORE', $string) ?: $string;

        // Remove control characters except newlines and tabs
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);

        // Remove BOM if present
        $string = preg_replace('/^\xEF\xBB\xBF/', '', $string);

        return $string;
    }
}

