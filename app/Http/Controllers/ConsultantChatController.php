<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consultant chat controller with Agent loop (ReAct pattern).
 *
 * The model can make multiple tool calls, aggregate results, think about them,
 * and only respond to the user when ready.
 */
class ConsultantChatController extends Controller
{
    private const SESSION_KEY = 'consultant_chat_history_v1';
    private const MAX_AGENT_ROUNDS = 5;

    public function index(): \Illuminate\View\View
    {
        return view('consultant');
    }

    public function message(Request $request, OpenRouterService $openRouter): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string'],
            'reset' => ['nullable', 'boolean'],
        ]);

        $reset = (bool) ($validated['reset'] ?? false);
        $text = trim((string) ($validated['message'] ?? ''));

        if ($reset) {
            $request->session()->forget(self::SESSION_KEY);

            return response()->json([
                'assistant_message' => 'Новый чат начат. Чем могу помочь?',
                'used_tools' => [],
            ]);
        }

        if ($text === '') {
            return response()->json([
                'assistant_message' => 'Напишите, пожалуйста, что вы ищете.',
                'used_tools' => [],
            ]);
        }

        /** @var array<int, array<string, mixed>> $history */
        $history = $request->session()->get(self::SESSION_KEY, []);

        $systemPrompt = view('ai-prompts.consultant-system')->render();

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ...$history,
            ['role' => 'user', 'content' => $text],
        ];

        $tools = $this->getToolDefinitions();

        $usedTools = [];
        $finalAssistantText = '';
        $allFoundUrls = [];

        // Agent loop (ReAct pattern): model can make multiple tool calls, think, aggregate
        for ($round = 0; $round < self::MAX_AGENT_ROUNDS; $round++) {
            Log::debug("ConsultantChat: Agent round {$round}");

            $raw = $openRouter->chatRaw($messages, [
                'model' => 'openai/gpt-5.2-chat',
                'tools' => $tools,
                'tool_choice' => 'auto',
            ]);

            $assistantMessage = (array) ($raw['message'] ?? []);
            $assistantContent = (string) ($assistantMessage['content'] ?? '');
            $toolCalls = $assistantMessage['tool_calls'] ?? null;

            // Add assistant message to conversation
            $messages[] = array_filter([
                'role' => 'assistant',
                'content' => $assistantContent,
                'tool_calls' => is_array($toolCalls) ? $toolCalls : null,
            ], static fn ($v) => $v !== null);

            $finalAssistantText = $assistantContent;

            // If no tool calls, agent is done thinking and ready to respond
            if (!is_array($toolCalls) || $toolCalls === []) {
                Log::debug('ConsultantChat: No tool calls, agent done');
                break;
            }

            // Process each tool call
            foreach ($toolCalls as $call) {
                $toolName = (string) Arr::get($call, 'function.name', '');
                $toolCallId = (string) Arr::get($call, 'id', '');
                $argsJson = (string) Arr::get($call, 'function.arguments', '{}');

                if ($toolCallId === '') {
                    continue;
                }

                $args = json_decode($argsJson, true);
                if (!is_array($args)) {
                    $args = [];
                }

                Log::debug("ConsultantChat: Tool call {$toolName}", ['args' => $args]);

                $result = match ($toolName) {
                    'search_by_title' => $this->searchByTitle($args),
                    'search_by_attributes' => $this->searchByAttributes($args),
                    'get_product_details' => $this->getProductDetails($args),
                    default => ['error' => "Unknown tool: {$toolName}"],
                };

                $usedTools[] = $toolName;

                // Collect found URLs for final output enforcement
                if (isset($result['urls']) && is_array($result['urls'])) {
                    $allFoundUrls = array_merge($allFoundUrls, $result['urls']);
                }

                // Add tool result to conversation so model can think about it
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'name' => $toolName,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }

            // Continue loop: model will see tool results and can decide to:
            // - call more tools
            // - aggregate results
            // - respond to user
        }

        // Persist bounded history (without system prompt)
        $newHistory = array_values(array_filter($messages, static function (array $m): bool {
            return ($m['role'] ?? null) !== 'system';
        }));
        $newHistory = array_slice($newHistory, -40);
        $request->session()->put(self::SESSION_KEY, $newHistory);

        // Enforce "URLs only" output when assistant decides to show search results
        if (
            $allFoundUrls !== []
            && (str_contains($finalAssistantText, 'http://') || str_contains($finalAssistantText, 'https://'))
        ) {
            // Extract URLs from the assistant text and combine with found URLs
            $finalAssistantText = $this->enforceUrlsOnlyOutput($finalAssistantText, $allFoundUrls);
        }

        return response()->json([
            'assistant_message' => $finalAssistantText,
            'used_tools' => array_values(array_unique($usedTools)),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getToolDefinitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_by_title',
                    'description' => 'Search products by title (product name, brand, model). Returns URLs and basic info. Use this for free-text search by product name.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Search query (product name, brand, model). Tokens are AND-matched.',
                            ],
                            'exclude' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Words to exclude from results (e.g., ["чехол", "стекло", "max"] to filter accessories or other models).',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 20,
                                'description' => 'Max results (default 10).',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_by_attributes',
                    'description' => 'Search products by JSON attributes (producer, model, color, storage, ram, display size, etc.). Use this for precise filtering by specifications.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'producer' => [
                                'type' => 'string',
                                'description' => 'Brand/manufacturer (e.g., "apple", "samsung", "xiaomi").',
                            ],
                            'model' => [
                                'type' => 'string',
                                'description' => 'Model name (e.g., "iphone 15 pro", "galaxy s25").',
                            ],
                            'color' => [
                                'type' => 'string',
                                'description' => 'Color (e.g., "black", "white", "titanium").',
                            ],
                            'storage_min_gb' => [
                                'type' => 'integer',
                                'description' => 'Minimum storage in GB.',
                            ],
                            'storage_max_gb' => [
                                'type' => 'integer',
                                'description' => 'Maximum storage in GB.',
                            ],
                            'ram_min_gb' => [
                                'type' => 'integer',
                                'description' => 'Minimum RAM in GB.',
                            ],
                            'display_min_size' => [
                                'type' => 'number',
                                'description' => 'Minimum display size in inches (e.g., 6.1).',
                            ],
                            'display_max_size' => [
                                'type' => 'number',
                                'description' => 'Maximum display size in inches.',
                            ],
                            'display_refresh_rate_min' => [
                                'type' => 'integer',
                                'description' => 'Minimum display refresh rate in Hz (e.g., 120).',
                            ],
                            'has_5g' => [
                                'type' => 'boolean',
                                'description' => 'Must support 5G.',
                            ],
                            'has_nfc' => [
                                'type' => 'boolean',
                                'description' => 'Must have NFC.',
                            ],
                            'has_wireless_charging' => [
                                'type' => 'boolean',
                                'description' => 'Must support wireless charging.',
                            ],
                            'is_used' => [
                                'type' => 'boolean',
                                'description' => 'Filter by new (false) or used/б-у (true).',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 20,
                                'description' => 'Max results (default 10).',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product_details',
                    'description' => 'Get detailed attributes for specific product URLs. Use this to compare products or verify specifications before showing to user.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'urls' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'List of product URLs to get details for (max 5).',
                            ],
                        ],
                        'required' => ['urls'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Search by title with ILIKE.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function searchByTitle(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $exclude = (array) ($args['exclude'] ?? []);
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));

        if ($query === '') {
            return [
                'count' => 0,
                'urls' => [],
                'message' => 'Empty query.',
            ];
        }

        $tokens = preg_split('/\s+/u', $query) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn (string $t) => mb_strlen($t) >= 2));

        $builder = Page::query()
            ->select(['url', 'title', 'json_attributes', 'is_used'])
            ->whereNotNull('title')
            ->where('is_product', true);

        foreach ($tokens as $token) {
            $builder->where('title', 'ilike', '%' . $token . '%');
        }

        foreach ($exclude as $exc) {
            $exc = trim((string) $exc);
            if (mb_strlen($exc) >= 2) {
                $builder->where('title', 'not ilike', '%' . $exc . '%');
            }
        }

        $results = $builder->limit($limit)->get();

        $urls = [];
        $items = [];
        foreach ($results as $page) {
            $urls[] = $page->url;
            $attrs = is_array($page->json_attributes) ? $page->json_attributes : [];
            $items[] = [
                'url' => $page->url,
                'title' => $page->title,
                'producer' => $attrs['producer'] ?? null,
                'model' => $attrs['model'] ?? null,
                'storage' => $attrs['storage']['humanSize'] ?? null,
                'color' => $attrs['color'] ?? null,
                'is_used' => (bool) $page->is_used,
            ];
        }

        return [
            'query' => $query,
            'exclude' => $exclude,
            'count' => count($urls),
            'urls' => $urls,
            'items' => $items,
        ];
    }

    /**
     * Search by JSON attributes using PostgreSQL JSON operators.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function searchByAttributes(array $args): array
    {
        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));

        $builder = Page::query()
            ->select(['url', 'title', 'json_attributes', 'is_used'])
            ->whereNotNull('json_attributes')
            ->where('is_product', true);

        $filters = [];

        // Producer (brand)
        if (!empty($args['producer'])) {
            $producer = mb_strtolower(trim((string) $args['producer']));
            $builder->whereRaw("LOWER(json_attributes->>'producer') LIKE ?", ["%{$producer}%"]);
            $filters['producer'] = $producer;
        }

        // Model
        if (!empty($args['model'])) {
            $model = mb_strtolower(trim((string) $args['model']));
            $builder->whereRaw("LOWER(json_attributes->>'model') LIKE ?", ["%{$model}%"]);
            $filters['model'] = $model;
        }

        // Color
        if (!empty($args['color'])) {
            $color = mb_strtolower(trim((string) $args['color']));
            $builder->whereRaw("LOWER(json_attributes->>'color') LIKE ?", ["%{$color}%"]);
            $filters['color'] = $color;
        }

        // Storage range
        if (!empty($args['storage_min_gb'])) {
            $builder->whereRaw("(json_attributes->'storage'->>'size')::int >= ?", [(int) $args['storage_min_gb']]);
            $filters['storage_min_gb'] = (int) $args['storage_min_gb'];
        }
        if (!empty($args['storage_max_gb'])) {
            $builder->whereRaw("(json_attributes->'storage'->>'size')::int <= ?", [(int) $args['storage_max_gb']]);
            $filters['storage_max_gb'] = (int) $args['storage_max_gb'];
        }

        // RAM range
        if (!empty($args['ram_min_gb'])) {
            $builder->whereRaw("(json_attributes->'ram'->>'size')::int >= ?", [(int) $args['ram_min_gb']]);
            $filters['ram_min_gb'] = (int) $args['ram_min_gb'];
        }

        // Display size range
        if (!empty($args['display_min_size'])) {
            $builder->whereRaw("(json_attributes->'display'->>'size')::float >= ?", [(float) $args['display_min_size']]);
            $filters['display_min_size'] = (float) $args['display_min_size'];
        }
        if (!empty($args['display_max_size'])) {
            $builder->whereRaw("(json_attributes->'display'->>'size')::float <= ?", [(float) $args['display_max_size']]);
            $filters['display_max_size'] = (float) $args['display_max_size'];
        }

        // Display refresh rate
        if (!empty($args['display_refresh_rate_min'])) {
            $builder->whereRaw("(json_attributes->'display'->>'refreshRate')::int >= ?", [(int) $args['display_refresh_rate_min']]);
            $filters['display_refresh_rate_min'] = (int) $args['display_refresh_rate_min'];
        }

        // Connectivity: 5G
        if (isset($args['has_5g']) && $args['has_5g'] === true) {
            $builder->whereRaw("(json_attributes->'connectivity'->>'fiveG')::boolean = true");
            $filters['has_5g'] = true;
        }

        // NFC
        if (isset($args['has_nfc']) && $args['has_nfc'] === true) {
            $builder->whereRaw("(json_attributes->'connectivity'->>'nfc')::boolean = true");
            $filters['has_nfc'] = true;
        }

        // Wireless charging
        if (isset($args['has_wireless_charging']) && $args['has_wireless_charging'] === true) {
            $builder->whereRaw("(json_attributes->'battery'->>'wirelessCharging')::boolean = true");
            $filters['has_wireless_charging'] = true;
        }

        // is_used (new vs used)
        if (isset($args['is_used'])) {
            $builder->where('is_used', (bool) $args['is_used']);
            $filters['is_used'] = (bool) $args['is_used'];
        }

        $results = $builder->limit($limit)->get();

        $urls = [];
        $items = [];
        foreach ($results as $page) {
            $urls[] = $page->url;
            $attrs = is_array($page->json_attributes) ? $page->json_attributes : [];
            $items[] = [
                'url' => $page->url,
                'title' => $page->title,
                'producer' => $attrs['producer'] ?? null,
                'model' => $attrs['model'] ?? null,
                'storage' => $attrs['storage']['humanSize'] ?? null,
                'ram' => $attrs['ram']['humanSize'] ?? null,
                'display_size' => $attrs['display']['humanSize'] ?? null,
                'display_refresh_rate' => $attrs['display']['humanRefreshRate'] ?? null,
                'color' => $attrs['color'] ?? null,
                'is_used' => (bool) $page->is_used,
            ];
        }

        return [
            'filters' => $filters,
            'count' => count($urls),
            'urls' => $urls,
            'items' => $items,
        ];
    }

    /**
     * Get detailed product info for specific URLs.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function getProductDetails(array $args): array
    {
        $urls = (array) ($args['urls'] ?? []);
        $urls = array_slice(array_values(array_filter($urls, 'is_string')), 0, 5);

        if ($urls === []) {
            return [
                'count' => 0,
                'products' => [],
            ];
        }

        $pages = Page::query()
            ->select(['url', 'title', 'json_attributes', 'is_used', 'product_summary', 'product_summary_specs'])
            ->whereIn('url', $urls)
            ->get();

        $products = [];
        foreach ($pages as $page) {
            $attrs = is_array($page->json_attributes) ? $page->json_attributes : [];
            $products[] = [
                'url' => $page->url,
                'title' => $page->title,
                'is_used' => (bool) $page->is_used,
                'summary' => $page->product_summary,
                'specs' => $page->product_summary_specs,
                'attributes' => [
                    'producer' => $attrs['producer'] ?? null,
                    'model' => $attrs['model'] ?? null,
                    'color' => $attrs['color'] ?? null,
                    'storage' => $attrs['storage'] ?? null,
                    'ram' => $attrs['ram'] ?? null,
                    'display' => $attrs['display'] ?? null,
                    'camera' => $attrs['camera'] ?? null,
                    'battery' => $attrs['battery'] ?? null,
                    'processor' => $attrs['processor'] ?? null,
                    'connectivity' => $attrs['connectivity'] ?? null,
                    'protection' => $attrs['protection'] ?? null,
                    'weight' => $attrs['humanWeight'] ?? $attrs['weight'] ?? null,
                    'release_year' => $attrs['releaseYear'] ?? null,
                ],
            ];
        }

        return [
            'count' => count($products),
            'products' => $products,
        ];
    }

    /**
     * When model decides to show results, enforce URLs-only output.
     */
    private function enforceUrlsOnlyOutput(string $assistantText, array $allFoundUrls): string
    {
        // Extract URLs from assistant text
        preg_match_all('/https?:\/\/[^\s<>"\']+/i', $assistantText, $matches);
        $textUrls = $matches[0] ?? [];

        // Combine and dedupe
        $allUrls = array_unique(array_merge($textUrls, $allFoundUrls));

        if ($allUrls === []) {
            return $assistantText;
        }

        // Return only URLs, one per line
        return implode("\n", array_values($allUrls));
    }
}
