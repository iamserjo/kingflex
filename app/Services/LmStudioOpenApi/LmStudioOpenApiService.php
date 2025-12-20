<?php

declare(strict_types=1);

namespace App\Services\LmStudioOpenApi;

use App\Services\Json\JsonParserService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal OpenAI-compatible client used for vision requests (screenshots).
 *
 * Endpoint: POST {base_url}/chat/completions
 * Env/config:
 * - LM_STUDIO_OPENAPI_BASE_URL
 * - LM_STUDIO_OPENAPI_MODEL
 */
class LmStudioOpenApiService
{
    private string $baseUrl;
    private string $model;
    private int $timeout;
    private int $maxTokens;
    private float $temperature;

    public function __construct()
    {
        $this->baseUrl = (string) config('lm_studio_openapi.base_url');
        $this->model = (string) config('lm_studio_openapi.model');
        $this->timeout = (int) config('lm_studio_openapi.timeout');
        $this->maxTokens = (int) config('lm_studio_openapi.max_tokens');
        $this->temperature = (float) config('lm_studio_openapi.temperature');
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->model !== '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @return array{
     *   content: string,
     *   model: string,
     *   usage: array<string, mixed>,
     *   error?: array{status: int|null, message: string, body: string|null, url?: string, path?: string}
     * }|null
     */
    public function chat(array $messages, array $options = []): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $model = (string) ($options['model'] ?? $this->model);

        try {
            $body = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => (int) ($options['max_tokens'] ?? $this->maxTokens),
                'temperature' => (float) ($options['temperature'] ?? $this->temperature),
                'stream' => false,
            ];

            if (!empty($options['response_format'])) {
                $body['response_format'] = $options['response_format'];
            }

            Log::debug('📤 Sending request to LM Studio OpenAPI', [
                'url' => rtrim($this->baseUrl, '/') . '/chat/completions',
                'model' => $model,
            ]);

            $response = $this->client()->post('/chat/completions', $body);
            $data = $response->json();

            // Some OpenAI-compatible servers return HTTP 200 with {"error": "..."} body
            if (is_array($data) && isset($data['error'])) {
                $message = is_string($data['error'])
                    ? $data['error']
                    : (json_encode($data['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'LM Studio OpenAPI error');

                Log::error('❌ LM Studio OpenAPI error (body)', [
                    'error' => $data['error'],
                ]);

                return [
                    'content' => '',
                    'model' => (string) ($data['model'] ?? $model),
                    'usage' => (array) ($data['usage'] ?? []),
                    'error' => [
                        'status' => $response->status(),
                        'message' => $message,
                        'body' => $response->body(),
                        'url' => (string) rtrim($this->baseUrl, '/') . '/chat/completions',
                        'path' => '/chat/completions',
                    ],
                ];
            }

            if (!$response->successful()) {
                Log::error('❌ LM Studio OpenAPI error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'content' => '',
                    'model' => $model,
                    'usage' => (array) ($data['usage'] ?? []),
                    'error' => [
                        'status' => $response->status(),
                        'message' => 'LM Studio OpenAPI request failed',
                        'body' => $response->body(),
                        'url' => (string) rtrim($this->baseUrl, '/') . '/chat/completions',
                        'path' => '/chat/completions',
                    ],
                ];
            }

            $content = (string) ($data['choices'][0]['message']['content'] ?? '');
            if ($content === '') {
                return [
                    'content' => '',
                    'model' => (string) ($data['model'] ?? $model),
                    'usage' => (array) ($data['usage'] ?? []),
                    'error' => [
                        'status' => $response->status(),
                        'message' => 'LM Studio OpenAPI returned empty assistant content',
                        'body' => $response->body(),
                        'url' => (string) rtrim($this->baseUrl, '/') . '/chat/completions',
                        'path' => '/chat/completions',
                    ],
                ];
            }

            return [
                'content' => $content,
                'model' => (string) ($data['model'] ?? $model),
                'usage' => (array) ($data['usage'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::error('❌ LM Studio OpenAPI exception', [
                'message' => $e->getMessage(),
            ]);

            return [
                'content' => '',
                'model' => $model,
                'usage' => [],
                'error' => [
                    'status' => null,
                    'message' => $e->getMessage(),
                    'body' => null,
                    'url' => (string) rtrim($this->baseUrl, '/') . '/chat/completions',
                    'path' => '/chat/completions',
                ],
            ];
        }
    }

    /**
     * @return array{
     *   content: string,
     *   model: string,
     *   usage: array<string, mixed>,
     *   error?: array{status: int|null, message: string, body: string|null, url?: string, path?: string}
     * }|null
     */
    public function chatWithImage(string $systemPrompt, string $userText, string $imageDataUrl, array $options = []): ?array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
                ],
            ],
        ];

        return $this->chat($messages, $options);
    }

    /**
     * Text-only chat request that must return a JSON object.
     *
     * Uses response_format=json_object (when supported by the server) and then
     * parses the assistant content with JsonParserService for robustness.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function chatJson(string $systemPrompt, string $userText, array $options = []): ?array
    {
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userText],
        ];

        // Some LM Studio OpenAI-compatible servers do not support json_object,
        // but accept only "text" or "json_schema". We enforce JSON via the prompt
        // and parse the returned text.
        $options['response_format'] = ['type' => 'text'];

        $response = $this->chat($messages, $options);
        if ($response === null || empty($response['content'])) {
            return null;
        }

        $jsonParser = app(JsonParserService::class);

        return $jsonParser->parse((string) $response['content']);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }
}


