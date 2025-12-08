<?php

declare(strict_types=1);

namespace App\Services\OpenRouter;

use App\Services\Json\JsonParserService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with OpenRouter API.
 * Provides chat completion and embedding generation capabilities.
 */
class OpenRouterService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private string $chatModel;
    private int $chatMaxTokens;
    private float $chatTemperature;
    private string $embeddingModel;
    private int $retryAttempts;
    private int $retryDelay;

    public function __construct()
    {
        $this->apiKey = config('openrouter.api_key');
        $this->baseUrl = config('openrouter.base_url');
        $this->timeout = config('openrouter.timeout');
        $this->chatModel = config('openrouter.chat_model');
        $this->chatMaxTokens = config('openrouter.chat_max_tokens');
        $this->chatTemperature = config('openrouter.chat_temperature');
        $this->embeddingModel = config('openrouter.embedding_model');
        $this->retryAttempts = config('openrouter.retry_attempts');
        $this->retryDelay = config('openrouter.retry_delay');
    }

    /**
     * Create a configured HTTP client for OpenRouter API.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->withHeaders([
                'HTTP-Referer' => config('openrouter.site_url'),
                'X-Title' => config('openrouter.site_name'),
            ])
            ->timeout($this->timeout)
            ->retry($this->retryAttempts, $this->retryDelay);
    }

    /**
     * Send a chat completion request.
     *
     * @param array<array{role: string, content: string|array}> $messages
     * @param array<string, mixed> $options Additional options
     * @return array{content: string, usage: array, model: string}|null
     */
    public function chat(array $messages, array $options = []): ?array
    {
        $model = $options['model'] ?? $this->chatModel;

        Log::debug('📤 Sending request to OpenRouter', [
            'url' => $this->baseUrl . '/chat/completions',
            'model' => $model,
            'has_api_key' => !empty($this->apiKey),
        ]);

        try {
            // Sanitize messages to ensure valid UTF-8
            $messages = $this->sanitizeMessages($messages);

            $requestBody = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $options['max_tokens'] ?? $this->chatMaxTokens,
                'temperature' => $options['temperature'] ?? $this->chatTemperature,
            ];

            // Only add response_format if specified
            if (!empty($options['response_format'])) {
                $requestBody['response_format'] = $options['response_format'];
            }

            $response = $this->client()->post('/chat/completions', $requestBody);

            if (!$response->successful()) {
                Log::error('❌ OpenRouter API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            Log::debug('📥 OpenRouter response received', [
                'model' => $data['model'] ?? 'unknown',
                'usage' => $data['usage'] ?? [],
            ]);

            return [
                'content' => $data['choices'][0]['message']['content'] ?? '',
                'usage' => $data['usage'] ?? [],
                'model' => $data['model'] ?? $model,
            ];
        } catch (\Exception $e) {
            Log::error('❌ OpenRouter exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Send a chat completion request with JSON response format.
     *
     * @param string $systemPrompt System prompt
     * @param string $userMessage User message
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed>|null Parsed JSON response
     */
    public function chatJson(string $systemPrompt, string $userMessage, array $options = []): ?array
    {
        Log::info('🤖 OpenRouter chatJson request', [
            'model' => $options['model'] ?? $this->chatModel,
            'system_prompt_length' => strlen($systemPrompt),
            'user_message_length' => strlen($userMessage),
        ]);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $options['response_format'] = ['type' => 'json_object'];

        $response = $this->chat($messages, $options);

        if ($response === null) {
            Log::error('❌ OpenRouter chatJson failed - no response');
            return null;
        }

        Log::debug('📨 OpenRouter raw response', [
            'content_length' => strlen($response['content']),
            'usage' => $response['usage'] ?? [],
        ]);

        // Use JsonParserService to parse response
        $jsonParser = app(JsonParserService::class);
        $parsed = $jsonParser->parse($response['content']);

        if ($parsed === null) {
            Log::error('❌ Failed to parse OpenRouter JSON response', [
                'content' => substr($response['content'], 0, 500),
            ]);
            return null;
        }

        Log::info('✅ OpenRouter chatJson success', [
            'keys' => array_keys($parsed),
            'page_type' => $parsed['page_type'] ?? null,
        ]);

        return $parsed;
    }

    /**
     * Send a chat completion request with an image.
     *
     * @param string $systemPrompt System prompt
     * @param string $userMessage User message
     * @param string $imageBase64 Base64 encoded image
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed>|null Parsed JSON response
     */
    public function chatWithImage(string $systemPrompt, string $userMessage, string $imageBase64, array $options = []): ?array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userMessage],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageBase64]],
                ],
            ],
        ];

        $options['response_format'] = ['type' => 'json_object'];

        $response = $this->chat($messages, $options);

        if ($response === null) {
            return null;
        }

        try {
            return json_decode($response['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('Failed to parse OpenRouter JSON response with image', [
                'content' => $response['content'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate embeddings for a text.
     *
     * @param string $text Text to embed
     * @return array<float>|null Vector embedding
     */
    public function createEmbedding(string $text): ?array
    {
        try {
            $response = $this->client()->post('/embeddings', [
                'model' => $this->embeddingModel,
                'input' => $text,
            ]);

            if (!$response->successful()) {
                Log::error('OpenRouter embedding request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            return $data['data'][0]['embedding'] ?? null;
        } catch (\Exception $e) {
            Log::error('OpenRouter embedding request exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Generate embeddings for multiple texts.
     *
     * @param array<string> $texts Texts to embed
     * @return array<array<float>>|null Array of vector embeddings
     */
    public function createEmbeddings(array $texts): ?array
    {
        try {
            $response = $this->client()->post('/embeddings', [
                'model' => $this->embeddingModel,
                'input' => $texts,
            ]);

            if (!$response->successful()) {
                Log::error('OpenRouter batch embedding request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            return array_map(fn($item) => $item['embedding'], $data['data'] ?? []);
        } catch (\Exception $e) {
            Log::error('OpenRouter batch embedding request exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Check if the API key is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Sanitize messages to ensure valid UTF-8 encoding.
     *
     * @param array<array{role: string, content: string|array}> $messages
     * @return array<array{role: string, content: string|array}>
     */
    private function sanitizeMessages(array $messages): array
    {
        return array_map(function ($message) {
            if (is_string($message['content'])) {
                $message['content'] = $this->sanitizeUtf8($message['content']);
            } elseif (is_array($message['content'])) {
                $message['content'] = array_map(function ($item) {
                    if (isset($item['text']) && is_string($item['text'])) {
                        $item['text'] = $this->sanitizeUtf8($item['text']);
                    }
                    return $item;
                }, $message['content']);
            }
            return $message;
        }, $messages);
    }

    /**
     * Sanitize UTF-8 string, removing invalid characters.
     *
     * @param string $string
     * @return string
     */
    private function sanitizeUtf8(string $string): string
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

        return $string;
    }
}

