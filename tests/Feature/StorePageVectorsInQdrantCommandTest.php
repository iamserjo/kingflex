<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Redis\PageLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Avoid requiring a running Redis in tests.
    $lock = Mockery::mock(PageLockService::class);
    $lock->shouldReceive('acquireLock')->andReturnTrue();
    $lock->shouldReceive('releaseLock')->andReturnNull();
    app()->instance(PageLockService::class, $lock);

    config()->set('openrouter.embedding_dimensions', 3);
    config()->set('qdrant.host', 'http://qdrant.test');
    config()->set('qdrant.collection', 'pages');
    config()->set('qdrant.vector_size', 3);
});

test('page:qdstore stores eligible page in qdrant and marks qdstored_at', function () {
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

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone',
        'last_crawled_at' => now(),
        'is_product' => true,
        'is_product_available' => true,
        'product_type_id' => $type->id,
        'product_type_detected_at' => now(),
        'product_summary_specs' => '8GB RAM; OLED',
        'product_abilities' => 'Calls, photos',
        'product_predicted_search_text' => 'apple phone 8gb, super phone 8gb, buy super phone',
        'sku' => 'ABC-123',
        'product_code' => 'PC-999',
        'product_model_number' => 'MN-777',
    ]);

    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('createEmbedding')
        ->once()
        ->andReturn([0.1, 0.2, 0.3]);
    app()->instance(OpenRouterService::class, $openRouter);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/collections/pages') && $request->method() === 'GET') {
            return Http::response([], 404);
        }
        if (str_contains($url, '/collections/pages') && $request->method() === 'PUT' && !str_contains($url, '/points')) {
            return Http::response(['result' => true], 200);
        }
        if (str_contains($url, '/collections/pages/points') && $request->method() === 'PUT') {
            return Http::response(['result' => true], 200);
        }

        return Http::response('not faked', 500);
    });

    $exit = Artisan::call('page:qdstore', [
        '--limit' => 1,
        '--attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();
    expect($page->qdstored_at)->not->toBeNull();
});

test('page:qdstore does not mark qdstored_at when qdrant upsert fails', function () {
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

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/2',
        'title' => 'Phone 2',
        'last_crawled_at' => now(),
        'is_product' => true,
        'is_product_available' => true,
        'product_type_id' => $type->id,
        'product_type_detected_at' => now(),
        'product_summary_specs' => 'Specs.',
        'product_abilities' => 'Abilities.',
        'product_predicted_search_text' => 'q1, q2, q3, q4, q5',
    ]);

    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('createEmbedding')
        ->once()
        ->andReturn([0.1, 0.2, 0.3]);
    app()->instance(OpenRouterService::class, $openRouter);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if ($url === 'http://qdrant.test/collections/pages' && $request->method() === 'GET') {
            return Http::response([], 200);
        }
        if (str_starts_with($url, 'http://qdrant.test/collections/pages/points') && $request->method() === 'PUT') {
            return Http::response(['error' => 'boom'], 500);
        }

        return Http::response('not faked', 500);
    });

    $exit = Artisan::call('page:qdstore', [
        '--limit' => 1,
        '--attempts' => 1,
    ]);

    expect($exit)->not->toBe(0);

    $page->refresh();
    expect($page->qdstored_at)->toBeNull();
});

test('page:qdstore skips pages without product recap fields', function () {
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

    Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/3',
        'title' => 'Phone 3',
        'last_crawled_at' => now(),
        'is_product' => true,
        'is_product_available' => true,
        'product_type_id' => $type->id,
        'product_type_detected_at' => now(),
        'product_summary_specs' => null,
        'product_abilities' => null,
        'product_predicted_search_text' => null,
    ]);

    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldNotReceive('createEmbedding');
    app()->instance(OpenRouterService::class, $openRouter);

    Http::fake(function (Request $request) {
        $url = $request->url();

        if ($url === 'http://qdrant.test/collections/pages' && $request->method() === 'GET') {
            return Http::response([], 200);
        }

        return Http::response('not faked', 500);
    });

    $exit = Artisan::call('page:qdstore', [
        '--limit' => 1,
        '--attempts' => 1,
    ]);

    expect($exit)->toBe(0);
});


