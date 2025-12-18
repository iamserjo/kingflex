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

test('command retries until it gets valid JSON and stores only is_product when is_product=false', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'example.test',
        'is_active' => true,
    ]);

    Storage::disk('local')->put('screenshots/test/not-a-product.png', 'fake-image-bytes');

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://example.test/not-a-product',
        'title' => 'About us',
        'screenshot_path' => 'screenshots/test/not-a-product.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->twice()
        ->andReturn(
            ['content' => 'not a json', 'model' => 'test-model'],
            ['content' => '{"is_product": false, "is_product_available": true, "product_type": "phone"}', 'model' => 'test-model'],
        );
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:product-type-detect', [
        '--limit' => 1,
        '--max-attempts' => 3,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->is_product)->toBeFalse()
        ->and($page->product_type_detected_at)->not->toBeNull()
        ->and($page->is_product_available)->toBeNull()
        ->and($page->product_type_id)->toBeNull();
});

test('command stores availability and product_type_id when is_product=true and type exists', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'shop.test',
        'is_active' => true,
    ]);

    $type = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['phone', 'телефон'],
        'structure' => ['producer' => 'any'],
    ]);

    Storage::disk('local')->put('screenshots/test/product.png', 'fake-image-bytes');

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone',
        'screenshot_path' => 'screenshots/test/product.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "Phone"}',
            'model' => 'test-model',
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:product-type-detect', [
        '--limit' => 1,
        '--max-attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->is_product)->toBeTrue()
        ->and($page->is_product_available)->toBeTrue()
        ->and($page->product_type_id)->toBe($type->id)
        ->and($page->product_type_detected_at)->not->toBeNull();
});


