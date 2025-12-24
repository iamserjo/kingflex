<?php

use App\Models\Domain;
use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use App\Services\Redis\PageLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Avoid requiring a running Redis in tests.
    $lock = \Mockery::mock(PageLockService::class);
    $lock->shouldReceive('acquireLock')->andReturnTrue();
    $lock->shouldReceive('releaseLock')->andReturnNull();
    app()->instance(PageLockService::class, $lock);
});

test('command extracts attributes and stores json_attributes + sku/product_code/model_number', function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.url' => 'https://s3.test']);

    $domain = Domain::query()->create([
        'domain' => 'shop.test',
        'is_active' => true,
    ]);

    $type = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['phone', 'телефон'],
        'structure' => [
            'producer' => 'any',
            'model' => 'any',
            'ram' => ['size' => 0, 'humanSize' => ''],
        ],
    ]);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    $shotKey = 'tests/screenshots/attributes.png';
    Storage::disk('s3')->put($shotKey, $png);
    $shotUrl = Storage::disk('s3')->url($shotKey);

    $contentKey = 'tests/content/attributes.txt';
    $contentHtml = '<div><h1>Super Phone</h1><div>SKU: ABC-123</div></div>';
    Storage::disk('s3')->put($contentKey, $contentHtml);
    $contentUrl = Storage::disk('s3')->url($contentKey);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone',
        'screenshot_path' => $shotUrl,
        'content_with_tags_purified' => $contentUrl,
        'last_crawled_at' => now(),
        'is_product' => true,
        'is_product_available' => true,
        'product_type_id' => $type->id,
        'product_type_detected_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('getVisionModel')->andReturn('test-vision-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => json_encode([
                'sku' => 'ABC-123',
                'product_code' => 'PC-999',
                'product_model_number' => 'MN-777',
                'attributes' => [
                    'producer' => 'acme',
                    'model' => 'super phone',
                    'ram' => ['size' => 8, 'humanSize' => '8GB'],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'model' => 'test-model',
            'usage' => [],
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:extract-attributes', [
        '--limit' => 1,
        '--attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->attributes_extracted_at)->not->toBeNull()
        ->and($page->sku)->toBe('ABC-123')
        ->and($page->product_code)->toBe('PC-999')
        ->and($page->product_model_number)->toBe('MN-777')
        ->and($page->json_attributes)->toMatchArray([
            'producer' => 'acme',
            'model' => 'super phone',
            'ram' => ['size' => 8, 'humanSize' => '8GB'],
        ]);
});





