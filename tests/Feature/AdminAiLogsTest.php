<?php

declare(strict_types=1);

use App\Models\AiRequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders admin ai logs page with pagination', function (): void {
    AiRequestLog::create([
        'trace_id' => (string) \Illuminate\Support\Str::uuid(),
        'provider' => AiRequestLog::PROVIDER_OPENROUTER,
        'model' => 'test-model',
        'http_method' => 'POST',
        'base_url' => 'https://example.test',
        'path' => '/chat/completions',
        'status_code' => 200,
        'duration_ms' => 123,
        'request_payload' => ['hello' => 'world'],
        'response_payload' => ['ok' => true],
        'usage' => ['prompt_tokens' => 1],
        'error' => null,
    ]);

    $resp = $this->get('/admin/logs/ai');

    $resp->assertOk();
    $resp->assertSee('Admin / AI Logs');
    $resp->assertSee('openrouter');
    $resp->assertSee('/chat/completions');
});


