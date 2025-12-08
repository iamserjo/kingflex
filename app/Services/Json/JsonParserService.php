<?php

declare(strict_types=1);

namespace App\Services\Json;

use Illuminate\Support\Facades\Log;

/**
 * Service for parsing JSON from text that may contain extra content.
 * Handles AI responses that include explanations around JSON.
 */
class JsonParserService
{
    /**
     * Extract and parse JSON from a string that may contain other text.
     *
     * @param string $text Text containing JSON
     * @return array<string, mixed>|null Parsed JSON array or null on failure
     */
    public function parse(string $text): ?array
    {
        // First, try direct parsing (fastest path)
        $result = $this->tryDirectParse($text);
        if ($result !== null) {
            return $result;
        }

        // Try to extract JSON object
        $result = $this->extractJsonObject($text);
        if ($result !== null) {
            return $result;
        }

        // Try to extract JSON array
        $result = $this->extractJsonArray($text);
        if ($result !== null) {
            return $result;
        }

        // Try to fix common JSON issues and parse again
        $result = $this->tryFixAndParse($text);
        if ($result !== null) {
            return $result;
        }

        Log::warning('Failed to parse JSON from text', [
            'text_preview' => substr($text, 0, 500),
        ]);

        return null;
    }

    /**
     * Try direct JSON parsing.
     *
     * @param string $text
     * @return array<string, mixed>|null
     */
    private function tryDirectParse(string $text): ?array
    {
        $trimmed = trim($text);

        // Check if it looks like JSON
        if (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) {
            return null;
        }

        try {
            $result = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return is_array($result) ? $result : null;
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Extract JSON object from text by finding matching braces.
     *
     * @param string $text
     * @return array<string, mixed>|null
     */
    private function extractJsonObject(string $text): ?array
    {
        // Find first opening brace
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        // Find matching closing brace
        $end = $this->findMatchingBrace($text, $start, '{', '}');
        if ($end === false) {
            return null;
        }

        $jsonString = substr($text, $start, $end - $start + 1);

        try {
            $result = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
            return is_array($result) ? $result : null;
        } catch (\JsonException $e) {
            // Try to fix the extracted JSON
            return $this->tryFixAndParse($jsonString);
        }
    }

    /**
     * Extract JSON array from text.
     *
     * @param string $text
     * @return array<string, mixed>|null
     */
    private function extractJsonArray(string $text): ?array
    {
        // Find first opening bracket
        $start = strpos($text, '[');
        if ($start === false) {
            return null;
        }

        // Find matching closing bracket
        $end = $this->findMatchingBrace($text, $start, '[', ']');
        if ($end === false) {
            return null;
        }

        $jsonString = substr($text, $start, $end - $start + 1);

        try {
            $result = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
            return is_array($result) ? $result : null;
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Find the matching closing brace/bracket.
     *
     * @param string $text
     * @param int $start Starting position of opening brace
     * @param string $open Opening character
     * @param string $close Closing character
     * @return int|false Position of closing brace or false
     */
    private function findMatchingBrace(string $text, int $start, string $open, string $close): int|false
    {
        $length = strlen($text);
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($char === '\\') {
                $escape = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Try to fix common JSON issues and parse.
     *
     * @param string $text
     * @return array<string, mixed>|null
     */
    private function tryFixAndParse(string $text): ?array
    {
        $fixed = $text;

        // Remove markdown code blocks
        $fixed = preg_replace('/```json\s*/i', '', $fixed);
        $fixed = preg_replace('/```\s*/', '', $fixed);

        // Remove trailing commas before closing braces/brackets
        $fixed = preg_replace('/,\s*([}\]])/', '$1', $fixed);

        // Fix single quotes to double quotes (outside of already double-quoted strings)
        // This is tricky, so we do a simple replacement for obvious cases
        $fixed = preg_replace("/(?<=[{,\[:])\s*'([^']+)'\s*(?=[,}\]:])/", '"$1"', $fixed);

        // Remove control characters
        $fixed = preg_replace('/[\x00-\x1F\x7F]/u', '', $fixed);

        // Fix unescaped newlines in strings
        $fixed = preg_replace('/(?<!\\\\)\n/', '\\n', $fixed);

        // Try to parse again
        try {
            $result = json_decode(trim($fixed), true, 512, JSON_THROW_ON_ERROR);
            return is_array($result) ? $result : null;
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Parse JSON and ensure it has specific keys.
     *
     * @param string $text
     * @param array<string> $requiredKeys
     * @return array<string, mixed>|null
     */
    public function parseWithKeys(string $text, array $requiredKeys): ?array
    {
        $result = $this->parse($text);

        if ($result === null) {
            return null;
        }

        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $result)) {
                Log::warning('Parsed JSON missing required key', [
                    'missing_key' => $key,
                    'available_keys' => array_keys($result),
                ]);
                return null;
            }
        }

        return $result;
    }

    /**
     * Safely encode data to JSON, handling encoding issues.
     *
     * @param mixed $data
     * @return string|false
     */
    public function safeEncode(mixed $data): string|false
    {
        // First attempt with standard flags
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json !== false) {
            return $json;
        }

        // If failed, try to sanitize the data
        $sanitized = $this->sanitizeForJson($data);
        return json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Sanitize data for JSON encoding.
     *
     * @param mixed $data
     * @return mixed
     */
    private function sanitizeForJson(mixed $data): mixed
    {
        if (is_string($data)) {
            // Remove invalid UTF-8 sequences
            $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            $data = @iconv('UTF-8', 'UTF-8//IGNORE', $data) ?: $data;
            // Remove control characters
            $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
            return $data;
        }

        if (is_array($data)) {
            return array_map([$this, 'sanitizeForJson'], $data);
        }

        if (is_object($data)) {
            $result = new \stdClass();
            foreach (get_object_vars($data) as $key => $value) {
                $result->$key = $this->sanitizeForJson($value);
            }
            return $result;
        }

        return $data;
    }
}

