<?php

use App\Models\Domain;
use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use App\Services\Redis\PageLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Avoid requiring a running Redis in tests.
    $lock = \Mockery::mock(PageLockService::class);
    $lock->shouldReceive('acquireLock')->andReturnTrue();
    $lock->shouldReceive('releaseLock')->andReturnNull();
    app()->instance(PageLockService::class, $lock);
});

test('command extracts attributes and stores json_attributes + sku/product_code/model_number', function () {
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

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone',
        'content_with_tags_purified' => '<div><h1>Super Phone</h1><div>SKU: ABC-123</div></div>',
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
    $openAi->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            'sku' => 'ABC-123',
            'product_code' => 'PC-999',
            'product_model_number' => 'MN-777',
            'attributes' => [
                'producer' => 'acme',
                'model' => 'super phone',
                'ram' => ['size' => 8, 'humanSize' => '8GB'],
            ],
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:extract-attributes', [
        '--limit' => 1,
        '--max-attempts' => 1,
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




