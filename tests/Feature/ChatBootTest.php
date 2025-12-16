<?php

use App\Services\SearchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // If routes are cached in the Docker volume, tests may see stale routes.
    Artisan::call('route:clear');
});

test('chatboot page is reachable', function () {
    $this->get('/chatboot')->assertOk();
});

test('chatboot message validates input', function () {
    $this->postJson('/chatboot/message', [])->assertStatus(422);
    $this->postJson('/chatboot/message', ['message' => ''])->assertStatus(422);
});

test('chatboot reset works without calling the model', function () {
    Http::preventStrayRequests();

    $this->postJson('/chatboot/message', [
        'reset' => true,
        'message' => '',
    ])->assertOk()->assertJson([
        'assistant_message' => '',
        'used_tools' => [],
        'tool_data' => [],
    ]);
});

test('chatboot uses tool calling to run search and returns final answer', function () {
    $mock = \Mockery::mock(SearchService::class);
    $mock->shouldReceive('search')
        ->once()
        ->with('ipad air')
        ->andReturn([
            'results' => [
                [
                    'page_id' => 1,
                    'url' => 'https://example.test/ipad-air',
                    'title' => 'iPad Air',
                    'summary' => 'A lightweight tablet.',
                    'recap_content' => 'Great for study and work.',
                    'page_type' => 'product',
                    'score' => 0.91,
                    'distance' => 0.18,
                ],
            ],
            'error' => null,
            'query_time_ms' => 12,
        ]);
    app()->instance(SearchService::class, $mock);

    $baseUrl = rtrim((string) config('openrouter.base_url'), '/');
    $chatUrl = $baseUrl . '/chat/completions';

    Http::fakeSequence($chatUrl)
        ->push([
            'model' => 'openai/gpt-4o-mini',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'search',
                                    'arguments' => '{"query":"ipad air"}',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => [],
        ], 200)
        ->push([
            'model' => 'openai/gpt-4o-mini',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Нашёл результаты по запросу “ipad air”. Вот самое релевантное: iPad Air — https://example.test/ipad-air (91%).',
                    ],
                ],
            ],
            'usage' => [],
        ], 200);

    $response = $this->postJson('/chatboot/message', [
        'message' => 'Найди ipad air',
    ])->assertOk();

    $response->assertJsonPath('used_tools.0', 'search');
    $response->assertJsonPath('tool_data.0.tool', 'search');
    $response->assertJsonPath('tool_data.0.result.query', 'ipad air');

    $response->assertJsonPath(
        'assistant_message',
        'Нашёл результаты по запросу “ipad air”. Вот самое релевантное: iPad Air — https://example.test/ipad-air (91%).'
    );
});



