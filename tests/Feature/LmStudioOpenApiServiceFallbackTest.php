<?php

declare(strict_types=1);

use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use Illuminate\Support\Facades\Http;

it('retries chatWithImage without images when LM Studio Vision add-on is not loaded', function (): void {
    config()->set('lm_studio_openapi.base_urls', ['http://lm-studio.test']);
    config()->set('lm_studio_openapi.model', 'qwen/qwen3-4b-2507');
    config()->set('lm_studio_openapi.timeout', 5);
    config()->set('lm_studio_openapi.max_tokens', 64);
    config()->set('lm_studio_openapi.temperature', 0.2);

    Http::fakeSequence()
        ->push([
            'error' => 'Error in iterating prediction stream: ValueError: Vision add-on is not loaded, but images were provided for processing',
        ], 400)
        ->push([
            'model' => 'qwen/qwen3-4b-2507',
            'choices' => [
                ['message' => ['content' => '{"ok":true}']],
            ],
        ], 200);

    /** @var LmStudioOpenApiService $svc */
    $svc = app()->make(LmStudioOpenApiService::class);

    $out = $svc->chatWithImage(
        systemPrompt: 'Return JSON only.',
        userText: 'Hello',
        imageDataUrl: 'data:image/png;base64,' . base64_encode('fake'),
        options: ['response_format' => ['type' => 'text']],
    );

    expect($out)->toBeArray();
    expect($out['content'])->toBe('{"ok":true}');
    Http::assertSentCount(2);

    $requests = Http::recorded();
    expect($requests)->toHaveCount(2);

    $firstPayload = $requests[0][0]->data();
    $secondPayload = $requests[1][0]->data();

    expect($firstPayload['messages'][1]['content'])->toBeArray();
    expect($secondPayload['messages'][1]['content'])->toBeString();
});

it('does not retry chatWithImage for non-vision errors', function (): void {
    config()->set('lm_studio_openapi.base_urls', ['http://lm-studio.test']);
    config()->set('lm_studio_openapi.model', 'qwen/qwen3-4b-2507');

    Http::fakeSequence()
        ->push(['error' => 'Some other error'], 400);

    /** @var LmStudioOpenApiService $svc */
    $svc = app()->make(LmStudioOpenApiService::class);

    $out = $svc->chatWithImage(
        systemPrompt: 'Return JSON only.',
        userText: 'Hello',
        imageDataUrl: 'data:image/png;base64,' . base64_encode('fake'),
    );

    expect($out)->toBeArray();
    expect($out['content'])->toBe('');
    Http::assertSentCount(1);
});


