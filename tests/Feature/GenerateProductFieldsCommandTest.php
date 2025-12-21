<?php

use App\Models\Domain;
use App\Models\Page;
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

test('page:recap --page generates product fields and normalizes predicted search queries', function () {
    $domain = Domain::query()->create([
        'domain' => 'shop.test',
        'is_active' => true,
    ]);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone',
        'meta_description' => 'Best phone ever',
        'page_type' => Page::TYPE_PRODUCT,
        'is_product' => true,
        'content_with_tags_purified' => '<main>Super Phone 128GB OLED</main>',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => '{"product_summary":"A great phone.","product_summary_specs":"128GB; OLED.","product_abilities":"Calls, photos.","product_predicted_search_text":"buy super phone, Super Phone price\\nSuper phone 128gb; super phone OLED, super phone review, buy super phone"}',
            'model' => 'test-model',
            'usage' => [],
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:recap', [
        '--page' => $page->id,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->product_summary)->toBe('A great phone.')
        ->and($page->product_summary_specs)->toBe('128GB; OLED.')
        ->and($page->product_abilities)->toBe('Calls, photos.')
        ->and($page->product_predicted_search_text)->toBe('buy super phone, Super Phone price, Super phone 128gb, super phone OLED, super phone review');
});

test('page:recap --page skips non-product pages and does not write product fields', function () {
    $domain = Domain::query()->create([
        'domain' => 'example.test',
        'is_active' => true,
    ]);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://example.test/about',
        'title' => 'About',
        'page_type' => Page::TYPE_OTHER,
        'is_product' => false,
        'content_with_tags_purified' => '<main>About us</main>',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldNotReceive('chat');
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:recap', [
        '--page' => $page->id,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();
    expect($page->product_summary)->toBeNull()
        ->and($page->product_summary_specs)->toBeNull()
        ->and($page->product_abilities)->toBeNull()
        ->and($page->product_predicted_search_text)->toBeNull();
});

test('page:recap fails if predicted search queries are fewer than 5 after normalization', function () {
    $domain = Domain::query()->create([
        'domain' => 'shop2.test',
        'is_active' => true,
    ]);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop2.test/product/2',
        'title' => 'Gadget',
        'page_type' => Page::TYPE_PRODUCT,
        'is_product' => true,
        'content_with_tags_purified' => '<main>Gadget</main>',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chat')
        ->once()
        ->andReturn([
            'content' => '{"product_summary":"Ok.","product_summary_specs":"Specs.","product_abilities":"Abilities.","product_predicted_search_text":"q1, q2, q3, q3"}',
            'model' => 'test-model',
            'usage' => [],
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:recap', [
        '--page' => $page->id,
    ]);

    expect($exit)->toBe(1);

    $page->refresh();
    expect($page->product_summary)->toBeNull()
        ->and($page->product_summary_specs)->toBeNull()
        ->and($page->product_abilities)->toBeNull()
        ->and($page->product_predicted_search_text)->toBeNull();
});


