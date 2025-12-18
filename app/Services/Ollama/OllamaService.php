<?php

declare(strict_types=1);

namespace App\Services\Ollama;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with Ollama API.
 * Uses native Ollama API format (/api/chat).
 *
 * @see https://github.com/ollama/ollama/blob/main/docs/api.md
 */
class OllamaService
{
    private string $baseUrl;
    private string $model;
    private int $timeout;
    private int $maxTokens;
    private float $temperature;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('ollama.base_url') ?? 'notseturl', '/');
        $this->model = config('ollama.model', 'mistral:latest');
        $this->timeout = (int) config('ollama.timeout', 120);
        $this->maxTokens = (int) config('ollama.max_tokens', 4096);
        $this->temperature = (float) config('ollama.temperature', 0.3);
    }

    /**
     * Create a configured HTTP client for Ollama API.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'ngrok-skip-browser-warning' => 'true',
            ]);
    }

    /**
     * Send a chat completion request using Ollama native API.
     *
     * @param array<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options Additional options
     * @return array{content: string, model: string, error?: array{url: string, path: string, status: int, body: string, message: string}}|null
     */
    public function chat(array $messages, array $options = []): ?array
    {
        $model = $options['model'] ?? $this->model;
        $path = '/api/chat';
        $url = $this->baseUrl . $path;

        Log::debug('📤 Sending request to Ollama', [
            'url' => $url,
            'model' => $model,
            'messages_count' => count($messages),
        ]);

        try {
            $requestBody = [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'options' => [
                    'num_predict' => $options['max_tokens'] ?? $this->maxTokens,
                    'temperature' => $options['temperature'] ?? $this->temperature,
                ],
            ];

            $response = $this->client()->post($path, $requestBody);

            if (!$response->successful()) {
                $errorBody = $response->body();
                Log::error('❌ Ollama API error', [
                    'url' => $url,
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $errorBody,
                ]);

                return [
                    'content' => '',
                    'model' => $model,
                    'error' => [
                        'url' => $url,
                        'path' => $path,
                        'status' => $response->status(),
                        'body' => substr($errorBody, 0, 500),
                        'message' => 'HTTP ' . $response->status() . ' error',
                    ],
                ];
            }

            $data = $response->json();

            Log::debug('📥 Ollama response received', [
                'model' => $data['model'] ?? 'unknown',
                'done' => $data['done'] ?? false,
            ]);

            // Ollama returns the assistant message in 'message' field
            $content = $data['message']['content'] ?? '';

            return [
                'content' => $content,
                'model' => $data['model'] ?? $model,
            ];
        } catch (\Exception $e) {
            Log::error('❌ Ollama exception', [
                'url' => $url,
                'path' => $path,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'content' => '',
                'model' => $model,
                'error' => [
                    'url' => $url,
                    'path' => $path,
                    'status' => 0,
                    'body' => '',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Generate a recap for page content using system and user prompts.
     *
     * @param string $systemPrompt System prompt
     * @param string $userContent User content to summarize
     * @param array<string, mixed> $options Additional options
     * @return string|null Generated recap or null on failure
     */
    public function generateRecap(string $systemPrompt, string $userContent, array $options = []): ?string
    {
        Log::info('🤖 Ollama generateRecap request', [
            'model' => $options['model'] ?? $this->model,
            'system_prompt_length' => strlen($systemPrompt),
            'user_content_length' => strlen($userContent),
        ]);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ];

        $response = $this->chat($messages, $options);

        if ($response === null) {
            Log::error('❌ Ollama generateRecap failed - no response');
            return null;
        }

        $recap = trim($response['content']);
        $recap = trim($recap, '"\'');

        Log::info('✅ Ollama generateRecap success', [
            'recap_length' => strlen($recap),
        ]);

        return $recap;
    }

    /**
     * Check if the service is configured properly.
     */
    public function isConfigured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->model);
    }

    /**
     * Get list of available models from Ollama.
     *
     * @return array<string> List of model names
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->client()->get('/api/tags');

            if (!$response->successful()) {
                Log::warning('⚠️ Failed to fetch Ollama models', [
                    'status' => $response->status(),
                ]);
                return [];
            }

            $data = $response->json();
            $models = [];

            foreach ($data['models'] ?? [] as $model) {
                $models[] = $model['name'] ?? $model['model'] ?? 'unknown';
            }

            return $models;
        } catch (\Exception $e) {
            Log::warning('⚠️ Exception fetching Ollama models', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get the configured model name.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the configured base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}

