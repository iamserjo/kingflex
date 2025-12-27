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

test('command extracts attributes from purified HTML text and stores json_attributes + product_original_article/model_number', function () {
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

    // Store purified HTML content in S3
    $contentKey = 'tests/content/text-v1-test.txt';
    $contentHtml = '<div><h1>Super Phone Pro</h1><div>Артикул: ABC-123</div><div>Код товара: PC-999</div><div>Model: MN-777</div><ul><li>Производитель: ACME</li><li>RAM: 8GB DDR4</li></ul></div>';
    Storage::disk('s3')->put($contentKey, $contentHtml);
    $contentUrl = Storage::disk('s3')->url($contentKey);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone Pro',
        'meta_description' => 'Best phone on the market',
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
    $openAi->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            'product_original_article' => '85605',
            'product_model_number' => 'MG8H4AF/A',
            'used' => false,
            'attributes' => [
                'producer' => 'acme',
                'model' => 'super phone pro',
                'ram' => ['size' => 8, 'humanSize' => '8GB'],
            ],
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:extract-attributes-with-text-v1', [
        '--limit' => 1,
        '--attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->attributes_extracted_at)->not->toBeNull()
        ->and($page->product_metadata_extracted_at)->not->toBeNull()
        ->and($page->product_original_article)->toBe('85605')
        ->and($page->product_model_number)->toBe('MG8H4AF/A')
        ->and($page->is_product_used)->toBe(false)
        ->and($page->json_attributes)->toMatchArray([
            'producer' => 'acme',
            'model' => 'super phone pro',
            'ram' => ['size' => 8, 'humanSize' => '8GB'],
        ]);
});

test('command skips pages without purified HTML content', function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.url' => 'https://s3.test']);

    $domain = Domain::query()->create([
        'domain' => 'shop.test',
        'is_active' => true,
    ]);

    $type = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['phone'],
        'structure' => ['producer' => 'any'],
    ]);

    // Page without content_with_tags_purified
    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/2',
        'title' => 'Phone',
        'content_with_tags_purified' => null,
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
    // chatJson should NOT be called
    $openAi->shouldNotReceive('chatJson');
    app()->instance(LmStudioOpenApiService::class, $openAi);

    // When processing by page ID (direct)
    $exit = Artisan::call('page:extract-attributes-with-text-v1', [
        '--page' => $page->id,
    ]);

    // Should return failure since page has no content
    expect($exit)->toBe(1);

    $page->refresh();
    expect($page->attributes_extracted_at)->toBeNull();
});

test('command skips pages without product_type_id', function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.url' => 'https://s3.test']);

    $domain = Domain::query()->create([
        'domain' => 'shop.test',
        'is_active' => true,
    ]);

    $contentKey = 'tests/content/text-v1-no-type.txt';
    Storage::disk('s3')->put($contentKey, '<div>Some content</div>');
    $contentUrl = Storage::disk('s3')->url($contentKey);

    // Page without product_type_id
    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/3',
        'title' => 'Phone',
        'content_with_tags_purified' => $contentUrl,
        'last_crawled_at' => now(),
        'is_product' => true,
        'is_product_available' => true,
        'product_type_id' => null,
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldNotReceive('chatJson');
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:extract-attributes-with-text-v1', [
        '--page' => $page->id,
    ]);

    expect($exit)->toBe(1);

    $page->refresh();
    expect($page->attributes_extracted_at)->toBeNull();
});

test('command retries on missing required keys', function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.url' => 'https://s3.test']);

    $domain = Domain::query()->create([
        'domain' => 'shop.test',
        'is_active' => true,
    ]);

    $type = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['phone'],
        'structure' => ['producer' => 'any'],
    ]);

    $contentKey = 'tests/content/text-v1-retry.txt';
    Storage::disk('s3')->put($contentKey, '<div><h1>Phone</h1></div>');
    $contentUrl = Storage::disk('s3')->url($contentKey);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/4',
        'title' => 'Phone',
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

    // First call returns incomplete response (missing 'attributes')
    // Second call returns complete response
    $openAi->shouldReceive('chatJson')
        ->twice()
        ->andReturn(
            // First attempt: missing 'attributes' key
            [
                'product_original_article' => '85605',
                'product_model_number' => null,
                'used' => true,
            ],
            // Second attempt: complete
            [
                'product_original_article' => '85605',
                'product_model_number' => null,
                'used' => true,
                'attributes' => ['producer' => 'acme'],
            ]
        );
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:extract-attributes-with-text-v1', [
        '--page' => $page->id,
        '--attempts' => 3,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();
    expect($page->attributes_extracted_at)->not->toBeNull()
        ->and($page->product_metadata_extracted_at)->not->toBeNull()
        ->and($page->product_original_article)->toBe('85605')
        ->and($page->is_product_used)->toBe(true)
        ->and($page->json_attributes)->toMatchArray(['producer' => 'acme']);
});

