<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiRequestLog;
use Illuminate\Support\Str;
use Throwable;

/**
 * Best-effort logger for AI provider HTTP calls.
 *
 * Important: logging must never break the main AI flow.
 */
final class AiRequestLogger
{
    public function start(
        string $provider,
        string $httpMethod,
        string $baseUrl,
        string $path,
        ?string $model,
        array $requestPayload,
    ): ?AiRequestLogContext {
        try {
            $log = AiRequestLog::create([
                'trace_id' => (string) Str::uuid(),
                'provider' => $provider,
                'model' => $model,
                'http_method' => strtoupper($httpMethod),
                'base_url' => $this->truncateString($baseUrl, 2048),
                'path' => $this->truncateString($path, 512),
                'request_payload' => $this->sanitizeForStorage($requestPayload),
            ]);

            return new AiRequestLogContext(
                log: $log,
                startedAtMicrotime: microtime(true),
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function finishSuccess(
        ?AiRequestLogContext $ctx,
        ?int $statusCode,
        mixed $responsePayload,
        ?string $responseBody,
        mixed $usage,
    ): void {
        if ($ctx === null) {
            return;
        }

        try {
            $ctx->log->update([
                'status_code' => $statusCode,
                'duration_ms' => $this->durationMs($ctx->startedAtMicrotime),
                'response_payload' => is_array($responsePayload) ? $this->sanitizeForStorage($responsePayload) : null,
                'response_body' => $responseBody !== null ? $this->truncateString($responseBody, $this->maxChars()) : null,
                'usage' => is_array($usage) ? $this->sanitizeForStorage($usage) : null,
                'error' => null,
            ]);
        } catch (Throwable) {
            // ignore
        }
    }

    public function finishError(
        ?AiRequestLogContext $ctx,
        ?int $statusCode,
        string $message,
        ?string $responseBody,
        mixed $responsePayload,
        mixed $usage,
        ?array $errorContext = null,
    ): void {
        if ($ctx === null) {
            return;
        }

        try {
            $ctx->log->update([
                'status_code' => $statusCode,
                'duration_ms' => $this->durationMs($ctx->startedAtMicrotime),
                'response_payload' => is_array($responsePayload) ? $this->sanitizeForStorage($responsePayload) : null,
                'response_body' => $responseBody !== null ? $this->truncateString($responseBody, $this->maxChars()) : null,
                'usage' => is_array($usage) ? $this->sanitizeForStorage($usage) : null,
                'error' => $this->sanitizeForStorage([
                    'message' => $this->truncateString($message, 2000),
                    'context' => is_array($errorContext) ? $errorContext : null,
                ]),
            ]);
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * Sanitize any nested structure for JSON storage:
     * - redact image/base64 fields
     * - truncate very long strings
     *
     * @return mixed
     */
    public function sanitizeForStorage(mixed $value, string $path = ''): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $key = is_string($k) ? $k : (string) $k;
                $nextPath = $path === '' ? $key : ($path . '.' . $key);
                $out[$k] = $this->sanitizeForStorage($v, $nextPath);
            }

            // Special-case OpenAI-style content: ['image_url' => ['url' => '...']]
            if (array_key_exists('image_url', $out) && is_array($out['image_url'])) {
                $img = $out['image_url'];
                if (array_key_exists('url', $img) && is_string($img['url'])) {
                    $img['url'] = '[redacted:image_url]';
                    $out['image_url'] = $img;
                }
            }

            return $out;
        }

        if (is_string($value)) {
            if ($this->shouldRedactString($value, $path)) {
                return '[redacted]';
            }

            return $this->truncateString($value, $this->maxChars());
        }

        return $value;
    }

    private function shouldRedactString(string $value, string $path): bool
    {
        $p = strtolower($path);
        if ($p !== '' && preg_match('/(?:image|base64|data_url|screenshot)/i', $p) === 1) {
            // Avoid redacting legitimate small text fields like "image" alt text by requiring some size.
            if ($this->strLen($value) >= 120) {
                return true;
            }
        }

        if (str_starts_with($value, 'data:image/')) {
            return true;
        }

        if (str_contains($value, ';base64,')) {
            return true;
        }

        return false;
    }

    private function maxChars(): int
    {
        $v = (int) (env('AI_LOG_MAX_CHARS', 50_000));
        return max(1_000, min(500_000, $v));
    }

    private function durationMs(float $startedAtMicrotime): int
    {
        return (int) max(0, round((microtime(true) - $startedAtMicrotime) * 1000));
    }

    private function truncateString(string $value, int $maxChars): string
    {
        if ($maxChars <= 0) {
            return '';
        }

        $len = $this->strLen($value);
        if ($len <= $maxChars) {
            return $value;
        }

        $head = $this->strSub($value, 0, $maxChars);

        return $head . '…[truncated ' . ($len - $maxChars) . ' chars]';
    }

    private function strLen(string $value): int
    {
        return function_exists('mb_strlen')
            ? (int) mb_strlen($value, 'UTF-8')
            : strlen($value);
    }

    private function strSub(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length, 'UTF-8');
        }

        return substr($value, $start, $length);
    }
}


