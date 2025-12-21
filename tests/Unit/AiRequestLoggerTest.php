<?php

declare(strict_types=1);

use App\Services\Ai\AiRequestLogger;

it('redacts data image urls and truncates long strings', function (): void {
    $logger = new AiRequestLogger();

    $input = [
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => str_repeat('a', 60_000)],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,' . str_repeat('x', 5000)]],
                ],
            ],
        ],
    ];

    $out = $logger->sanitizeForStorage($input);

    expect($out)->toBeArray();
    expect($out['messages'][0]['content'][1]['image_url']['url'])->toBe('[redacted:image_url]');
    expect($out['messages'][0]['content'][0]['text'])->toContain('…[truncated');
});


