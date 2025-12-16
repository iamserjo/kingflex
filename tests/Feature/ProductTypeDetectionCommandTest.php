<?php

use App\Models\Domain;
use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\Ollama\OllamaService;
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

test('command retries until it gets valid JSON and stores only is_product when is_product=false', function () {
    $domain = Domain::query()->create([
        'domain' => 'example.test',
        'is_active' => true,
    ]);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://example.test/not-a-product',
        'title' => 'About us',
        'content_with_tags_purified' => 'This is an informational page. Contact us.',
        'last_crawled_at' => now(),
    ]);

    $ollama = \Mockery::mock(OllamaService::class);
    $ollama->shouldReceive('isConfigured')->andReturnTrue();
    $ollama->shouldReceive('getBaseUrl')->andReturn('http://ollama.test');
    $ollama->shouldReceive('getModel')->andReturn('test-model');
    $ollama->shouldReceive('chat')
        ->twice()
        ->andReturn(
            ['content' => 'not a json', 'model' => 'test-model'],
            ['content' => '{"is_product": false, "is_product_available": true, "product_type": "phone"}', 'model' => 'test-model'],
        );
    app()->instance(OllamaService::class, $ollama);

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

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone',
        'content_with_tags_purified' => 'Buy now. Available for purchase.',
        'last_crawled_at' => now(),
    ]);

    $ollama = \Mockery::mock(OllamaService::class);
    $ollama->shouldReceive('isConfigured')->andReturnTrue();
    $ollama->shouldReceive('getBaseUrl')->andReturn('http://ollama.test');
    $ollama->shouldReceive('getModel')->andReturn('test-model');
    $ollama->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "Phone"}',
            'model' => 'test-model',
        ]);
    app()->instance(OllamaService::class, $ollama);

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

