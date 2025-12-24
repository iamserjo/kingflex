<?php

declare(strict_types=1);

namespace App\Services\LmStudioOpenApi;

use App\Models\AiRequestLog;
use App\Services\Ai\AiRequestLogger;
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
 * - LM_STUDIO_VISION_MODEL (optional, for image requests)
 */
class LmStudioOpenApiService
{
    private string $baseUrl;
    private string $model;
    private string $visionModel;
    private int $timeout;
    private int $maxTokens;
    private float $temperature;

    public function __construct()
    {
        $aiHosts = (array) config('lm_studio_openapi.base_urls');
        $this->baseUrl = (string) $aiHosts[array_rand($aiHosts)];
        $this->model = (string) config('lm_studio_openapi.model');
        $visionModel = trim((string) config('lm_studio_openapi.vision_model', ''));
        $this->visionModel = $visionModel !== '' ? $visionModel : $this->model;
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

    public function getVisionModel(): string
    {
        return $this->visionModel;
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

        /** @var AiRequestLogger $aiLogger */
        $aiLogger = app(AiRequestLogger::class);
        $aiCtx = null;

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

            $aiCtx = $aiLogger->start(
                provider: AiRequestLog::PROVIDER_OPENAI_COMPATIBLE,
                httpMethod: 'POST',
                baseUrl: $this->baseUrl,
                path: '/chat/completions',
                model: $model,
                requestPayload: $body,
            );

            $response = $this->client()->post('/chat/completions', $body);
            $data = $response->json();
            $usage = is_array($data) ? ($data['usage'] ?? []) : [];

            // Some OpenAI-compatible servers return HTTP 200 with {"error": "..."} body
            if (is_array($data) && isset($data['error'])) {
                $message = is_string($data['error'])
                    ? $data['error']
                    : (json_encode($data['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'LM Studio OpenAPI error');

                Log::error('❌ LM Studio OpenAPI error (body)', [
                    'error' => $data['error'],
                ]);

                $aiLogger->finishError(
                    ctx: $aiCtx,
                    statusCode: $response->status(),
                    message: $message,
                    responseBody: $response->body(),
                    responsePayload: $data,
                    usage: $usage,
                    errorContext: [
                        'type' => 'api_error_body',
                    ],
                );

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

                $aiLogger->finishError(
                    ctx: $aiCtx,
                    statusCode: $response->status(),
                    message: 'LM Studio OpenAPI request failed',
                    responseBody: $response->body(),
                    responsePayload: is_array($data) ? $data : null,
                    usage: $usage,
                    errorContext: [
                        'type' => 'http_error',
                    ],
                );

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
                $aiLogger->finishError(
                    ctx: $aiCtx,
                    statusCode: $response->status(),
                    message: 'LM Studio OpenAPI returned empty assistant content',
                    responseBody: $response->body(),
                    responsePayload: $data,
                    usage: $usage,
                    errorContext: [
                        'type' => 'empty_content',
                    ],
                );

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

            $aiLogger->finishSuccess(
                ctx: $aiCtx,
                statusCode: $response->status(),
                responsePayload: $data,
                responseBody: $response->body(),
                usage: $usage,
            );

            return [
                'content' => $content,
                'model' => (string) ($data['model'] ?? $model),
                'usage' => (array) ($data['usage'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::error('❌ LM Studio OpenAPI exception', [
                'message' => $e->getMessage(),
            ]);

            $aiLogger->finishError(
                ctx: $aiCtx,
                statusCode: null,
                message: $e->getMessage(),
                responseBody: null,
                responsePayload: null,
                usage: null,
                errorContext: [
                    'type' => 'exception',
                    'exception_class' => $e::class,
                ],
            );

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
        // Default image requests to the vision model unless explicitly overridden.
        $options['model'] ??= $this->visionModel;

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

        $response = $this->chat($messages, $options);
        if ($response === null) {
            return null;
        }

        // Graceful fallback: LM Studio may be running without Vision add-on; sending images then fails.
        // In that case we retry once with a text-only payload so pipelines can continue (with lower accuracy).
        if (
            ($response['content'] ?? '') === ''
            && $this->isVisionAddonNotLoadedError($response['error']['message'] ?? null)
            && empty($options['__disable_vision_fallback'])
        ) {
            Log::warning('🖼️➡️📝 LM Studio Vision add-on not loaded; retrying request without images', [
                'model' => (string) ($options['model'] ?? $this->model),
                'url' => rtrim($this->baseUrl, '/') . '/chat/completions',
            ]);

            $textOnlyMessages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userText],
            ];

            $fallbackOptions = $options;
            $fallbackOptions['__disable_vision_fallback'] = true;

            return $this->chat($textOnlyMessages, $fallbackOptions);
        }

        return $response;
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

    private function isVisionAddonNotLoadedError(mixed $message): bool
    {
        if (!is_string($message) || $message === '') {
            return false;
        }

        return str_contains($message, 'Vision add-on is not loaded')
            || str_contains($message, 'images were provided for processing');
    }
}


