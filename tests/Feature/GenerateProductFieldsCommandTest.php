<?php

use App\Models\Domain;
use App\Models\Page;
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

test('page:recap --page generates product fields and normalizes predicted search queries', function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.url' => 'https://s3.test']);

    $domain = Domain::query()->create([
        'domain' => 'shop.test',
        'is_active' => true,
    ]);

    $shotKey = 'tests/screenshots/test-page-recap.png';
    Storage::disk('s3')->put($shotKey, base64_decode(
        // 1x1 PNG
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2o2V8AAAAASUVORK5CYII=',
        true
    ) ?: '');
    $shotUrl = Storage::disk('s3')->url($shotKey);

    $contentKey = 'tests/content/test-page-recap.txt';
    Storage::disk('s3')->put($contentKey, '<main>Super Phone 128GB OLED</main>');
    $contentUrl = Storage::disk('s3')->url($contentKey);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/product/1',
        'title' => 'Super Phone',
        'meta_description' => 'Best phone ever',
        'page_type' => Page::TYPE_PRODUCT,
        'is_product' => true,
        'content_with_tags_purified' => $contentUrl,
        'last_crawled_at' => now(),
        'screenshot_path' => $shotUrl,
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"product_summary":"A great phone.","product_summary_specs":"128GB; OLED.","product_abilities":"Calls, photos.","product_predicted_search_text":"buy super phone, Super Phone price\\nSuper phone 128gb; super phone OLED, super phone review, buy super phone"}',
            'model' => 'test-model',
            'usage' => [],
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:recap', [
        '--page' => $page->id,
        '--attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->product_summary)->toBe('A great phone.')
        ->and($page->product_summary_specs)->toBe('128GB; OLED.')
        ->and($page->product_abilities)->toBe('Calls, photos.')
        ->and($page->product_predicted_search_text)->toBe('buy super phone, Super Phone price, Super phone 128gb, super phone OLED, super phone review');
});

test('page:recap --page skips non-product pages and does not write product fields', function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.url' => 'https://s3.test']);

    $domain = Domain::query()->create([
        'domain' => 'example.test',
        'is_active' => true,
    ]);

    $contentKey = 'tests/content/about.txt';
    Storage::disk('s3')->put($contentKey, '<main>About us</main>');
    $contentUrl = Storage::disk('s3')->url($contentKey);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://example.test/about',
        'title' => 'About',
        'page_type' => Page::TYPE_OTHER,
        'is_product' => false,
        'content_with_tags_purified' => $contentUrl,
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldNotReceive('chatWithImage');
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:recap', [
        '--page' => $page->id,
        '--attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();
    expect($page->product_summary)->toBeNull()
        ->and($page->product_summary_specs)->toBeNull()
        ->and($page->product_abilities)->toBeNull()
        ->and($page->product_predicted_search_text)->toBeNull();
});

test('page:recap fails if predicted search queries are fewer than 5 after normalization', function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.url' => 'https://s3.test']);

    $domain = Domain::query()->create([
        'domain' => 'shop2.test',
        'is_active' => true,
    ]);

    $shotKey = 'tests/screenshots/test-page-recap-fail.png';
    Storage::disk('s3')->put($shotKey, base64_decode(
        // 1x1 PNG
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2o2V8AAAAASUVORK5CYII=',
        true
    ) ?: '');
    $shotUrl = Storage::disk('s3')->url($shotKey);

    $contentKey = 'tests/content/test-page-recap-fail.txt';
    Storage::disk('s3')->put($contentKey, '<main>Gadget</main>');
    $contentUrl = Storage::disk('s3')->url($contentKey);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop2.test/product/2',
        'title' => 'Gadget',
        'page_type' => Page::TYPE_PRODUCT,
        'is_product' => true,
        'content_with_tags_purified' => $contentUrl,
        'last_crawled_at' => now(),
        'screenshot_path' => $shotUrl,
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"product_summary":"Ok.","product_summary_specs":"Specs.","product_abilities":"Abilities.","product_predicted_search_text":"q1, q2, q3, q3"}',
            'model' => 'test-model',
            'usage' => [],
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:recap', [
        '--page' => $page->id,
        '--attempts' => 1,
    ]);

    expect($exit)->toBe(1);

    $page->refresh();
    expect($page->product_summary)->toBeNull()
        ->and($page->product_summary_specs)->toBeNull()
        ->and($page->product_abilities)->toBeNull()
        ->and($page->product_predicted_search_text)->toBeNull();
});


