<?php

declare(strict_types=1);

namespace App\Services\ChatBoot;

use App\Services\OpenRouter\OpenRouterService;
use App\Services\SearchService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

final class ChatBootService
{
    private const string SESSION_KEY = 'chatboot.messages';

    private const int MAX_HISTORY_MESSAGES = 20;

    public function __construct(
        private readonly OpenRouterService $openRouter,
        private readonly SearchService $searchService,
    ) {}

    /**
     * @return array{assistant_message: string, used_tools: array<int, string>, tool_data: array<int, array<string, mixed>>}
     */
    public function sendMessage(string $message, bool $reset = false): array
    {
        if ($reset) {
            $this->reset();
        }

        if ($reset && trim($message) === '') {
            return [
                'assistant_message' => '',
                'used_tools' => [],
                'tool_data' => [],
            ];
        }

        $systemPrompt = (string) view('ai-prompts.chatboot-system')->render();

        /** @var array<int, array{role: string, content: string}> $history */
        $history = Session::get(self::SESSION_KEY, []);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$history,
            ['role' => 'user', 'content' => $message],
        ];

        $tools = $this->tools();

        $usedTools = [];
        $toolData = [];

        // 1st model call: allow tool calling
        $first = $this->openRouter->chatRaw($messages, [
            'model' => env('CHATBOT_MODEL'),
            'temperature' => 0.3,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ]);

        if ($first === null || isset($first['error'])) {
            $error = $first['error'] ?? null;
            $status = is_array($error) ? ($error['status'] ?? null) : null;

            if ($status === 429) {
                return [
                    'assistant_message' => 'OpenRouter rate limit exceeded (429). Please try again in a minute or stop background AI jobs that use the same API key.',
                    'used_tools' => [],
                    'tool_data' => [],
                ];
            }

            return [
                'assistant_message' => 'OpenRouter request failed. Please check `storage/logs/laravel.log` for details.',
                'used_tools' => [],
                'tool_data' => [],
            ];
        }

        $assistantMessage = $first['message'] ?? [];
        $toolCalls = Arr::get($assistantMessage, 'tool_calls', []);

        // No tool calls: finalize immediately
        if (!is_array($toolCalls) || $toolCalls === []) {
            $finalContent = (string) ($assistantMessage['content'] ?? '');

            $this->appendToHistory(['role' => 'user', 'content' => $message]);
            $this->appendToHistory(['role' => 'assistant', 'content' => $finalContent]);

            return [
                'assistant_message' => $finalContent,
                'used_tools' => [],
                'tool_data' => [],
            ];
        }

        // Tool calling round: include assistant tool_calls + tool results, then ask model for final answer.
        $messagesWithTools = $messages;
        $messagesWithTools[] = [
            'role' => 'assistant',
            'content' => (string) ($assistantMessage['content'] ?? ''),
            'tool_calls' => $toolCalls,
        ];

        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall)) {
                continue;
            }

            $toolName = Arr::get($toolCall, 'function.name');
            $toolCallId = (string) ($toolCall['id'] ?? '');
            $rawArguments = (string) Arr::get($toolCall, 'function.arguments', '');

            $usedTools[] = (string) $toolName;

            if ($toolName !== 'search') {
                $toolResult = [
                    'error' => 'Unknown tool: ' . (string) $toolName,
                ];
            } else {
                $args = json_decode($rawArguments, true);
                $query = is_array($args) ? (string) ($args['query'] ?? '') : '';

                $toolResult = $this->runSearchTool($query);
            }

            $toolData[] = [
                'tool' => (string) $toolName,
                'arguments' => $rawArguments,
                'result' => $toolResult,
            ];

            if ($toolCallId === '') {
                continue;
            }

            $messagesWithTools[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ];
        }

        $second = $this->openRouter->chatRaw($messagesWithTools, [
            'model' => env('CHATBOT_MODEL'),
            'temperature' => 0.3,
            'tools' => $tools,
            // Many providers support tool_choice=none for the final turn; keep tools anyway for safety.
            'tool_choice' => 'none',
        ]);

        if ($second === null || isset($second['error'])) {
            Log::warning('[ChatBoot] Second OpenRouter call failed after tool execution');

            return [
                'assistant_message' => 'Sorry, I could not finish the response after searching.',
                'used_tools' => array_values(array_unique($usedTools)),
                'tool_data' => $toolData,
            ];
        }

        $finalContent = (string) (($second['message']['content'] ?? '') ?: '');

        $this->appendToHistory(['role' => 'user', 'content' => $message]);
        $this->appendToHistory(['role' => 'assistant', 'content' => $finalContent]);

        return [
            'assistant_message' => $finalContent,
            'used_tools' => array_values(array_unique($usedTools)),
            'tool_data' => $toolData,
        ];
    }

    public function reset(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * @return array<int, array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search',
                    'description' => 'Semantic search in MarketKing database (pages). Returns relevant pages with title, url and relevance score.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query from the user',
                            ],
                        ],
                        'required' => ['query'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{query: string, error: string|null, query_time_ms: int, results: array<int, array<string, mixed>>}
     */
    private function runSearchTool(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [
                'query' => $query,
                'error' => 'Empty query',
                'query_time_ms' => 0,
                'results' => [],
            ];
        }

        $result = $this->searchService->search($query);

        return [
            'query' => $query,
            'error' => $result['error'] ?? null,
            'query_time_ms' => (int) ($result['query_time_ms'] ?? 0),
            'results' => array_slice((array) ($result['results'] ?? []), 0, 10),
        ];
    }

    /**
     * @param array{role: string, content: string} $message
     */
    private function appendToHistory(array $message): void
    {
        /** @var array<int, array{role: string, content: string}> $history */
        $history = Session::get(self::SESSION_KEY, []);

        $history[] = [
            'role' => (string) $message['role'],
            'content' => (string) $message['content'],
        ];

        if (count($history) > self::MAX_HISTORY_MESSAGES) {
            $history = array_slice($history, -self::MAX_HISTORY_MESSAGES);
        }

        Session::put(self::SESSION_KEY, $history);
    }
}



