<?php

use App\Models\Domain;
use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\LmStudioOpenApi\LmStudioOpenApiService;
use App\Services\OpenRouter\OpenRouterService;
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

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/not-a-product.png', $png);

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

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/product.png', $png);

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

test('command can map product_type_id when model returns a delimited product_type string', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'shop2.test',
        'is_active' => true,
    ]);

    $type = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['phone', 'телефон'],
        'structure' => ['producer' => 'any'],
    ]);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/product2.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop2.test/product/1',
        'title' => 'Super Phone 2',
        'screenshot_path' => 'screenshots/test/product2.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "Phone|Tablet|Case"}',
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
        ->and($page->product_type_id)->toBe($type->id);
});

test('command creates a new type and attaches it when product_type does not exist (single)', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'new-type.test',
        'is_active' => true,
    ]);

    $openRouter = \Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            'tags' => ['laptop', 'notebook', 'ноутбук'],
            'producer' => 'any',
            'model' => 'any',
        ]);
    app()->instance(OpenRouterService::class, $openRouter);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/new-type.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://new-type.test/product/1',
        'title' => 'Mystery Laptop',
        'screenshot_path' => 'screenshots/test/new-type.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "Laptop"}',
            'model' => 'test-model',
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:product-type-detect', [
        '--limit' => 1,
        '--max-attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    $created = TypeStructure::query()->where('type_normalized', 'laptop')->first();
    expect($created)->not->toBeNull()
        ->and($created->tags)->toContain('laptop')
        ->and($created->structure)->toMatchArray([
            'producer' => 'any',
            'model' => 'any',
        ]);

    expect($page->product_type_id)->toBe($created->id);
});

test('command creates a new type and attaches it when product_type is a slash-delimited list', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'new-type2.test',
        'is_active' => true,
    ]);

    $openRouter = \Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            'tags' => ['phone', 'телефон'],
            'producer' => 'any',
        ]);
    app()->instance(OpenRouterService::class, $openRouter);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/new-type2.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://new-type2.test/product/1',
        'title' => 'Ambiguous Device',
        'screenshot_path' => 'screenshots/test/new-type2.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "phone/laptop"}',
            'model' => 'test-model',
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:product-type-detect', [
        '--limit' => 1,
        '--max-attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    // First candidate should be used for creation.
    $created = TypeStructure::query()->where('type_normalized', 'phone')->first();
    expect($created)->not->toBeNull()
        ->and($created->tags)->toContain('phone')
        ->and($created->structure)->toMatchArray([
            'producer' => 'any',
        ]);

    expect($page->product_type_id)->toBe($created->id);
});

test('command backfills pages with product_type_detected_at set but product_type_id missing (without --force)', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'backfill.test',
        'is_active' => true,
    ]);

    $openRouter = \Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            'tags' => ['phone', 'смартфон'],
            'producer' => 'any',
        ]);
    app()->instance(OpenRouterService::class, $openRouter);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/backfill.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://backfill.test/product/1',
        'title' => 'Backfill Phone',
        'screenshot_path' => 'screenshots/test/backfill.png',
        'last_crawled_at' => now(),
        // Simulate a previously processed product page where mapping failed.
        'is_product' => true,
        'is_product_available' => true,
        'product_type_detected_at' => now()->subDay(),
        'product_type_id' => null,
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
        // intentionally no --force
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->product_type_id)->not->toBeNull()
        ->and($page->is_product)->toBeTrue();
});

test('command forces is_product=false for listing/filter URLs (e.g. /ch-...) even if model says product', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'ti.ua',
        'is_active' => true,
    ]);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/listing.png', $png);

    // URL pattern that indicates filter/listing page.
    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://ti.ua/ua/stabilizatory/ch-proizvoditel-feiyu/',
        'title' => 'Стабілізатори Feiyu',
        'screenshot_path' => 'screenshots/test/listing.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            // Even if model misclassifies this page as a product, the command must override it.
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "speaker"}',
            'model' => 'test-model',
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:product-type-detect', [
        '--limit' => 1,
        '--max-attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();

    expect($page->is_product)->toBeFalse()
        ->and($page->is_product_available)->toBeNull()
        ->and($page->product_type_id)->toBeNull()
        ->and($page->product_type_detected_at)->not->toBeNull();
});

test('command treats string availability like \"in stock\" as true', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'shop-availability.test',
        'is_active' => true,
    ]);

    TypeStructure::query()->create([
        'type' => 'Console',
        'type_normalized' => 'console',
        'tags' => ['console', 'gaming console'],
        'structure' => ['producer' => 'any'],
    ]);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/availability.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop-availability.test/product/1',
        'title' => 'Xbox Series X',
        'screenshot_path' => 'screenshots/test/availability.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"is_product": true, "is_product_available": "in stock", "product_type": "console"}',
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
        ->and($page->is_product_available)->toBeTrue();
});

test('command overrides model availability to true when page text clearly indicates in-stock + buy button', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'ti.ua',
        'is_active' => true,
    ]);

    TypeStructure::query()->create([
        'type' => 'Console',
        'type_normalized' => 'console',
        'tags' => ['console', 'gaming console'],
        'structure' => ['producer' => 'any'],
    ]);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/avail-override.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://ti.ua/ua/igrovaya-pristavka-microsoft-xbox-series-x.html',
        'title' => 'Xbox Series X',
        'meta_description' => 'Замовляй та забери сьогодні',
        'content_with_tags_purified' => '<main>В наявності <button>Купити</button></main>',
        'screenshot_path' => 'screenshots/test/avail-override.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            // Model is wrong here; command should override to true from page text.
            'content' => '{"is_product": true, "is_product_available": false, "product_type": "console"}',
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
        ->and($page->is_product_available)->toBeTrue();
});

test('command overrides model availability to false when page text clearly indicates out-of-stock', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'ti.ua',
        'is_active' => true,
    ]);

    TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['phone'],
        'structure' => ['producer' => 'any'],
    ]);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/oos-override.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://ti.ua/ua/product/2.html',
        'title' => 'Some product',
        'content_with_tags_purified' => '<main>Немає в наявності</main>',
        'screenshot_path' => 'screenshots/test/oos-override.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            // Model is wrong here; command should override to false from page text.
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "phone"}',
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
        ->and($page->is_product_available)->toBeFalse();
});

test('command maps compound product_type like \"gaming_console\" to existing \"console\" type_structures', function () {
    Storage::fake('local');

    $domain = Domain::query()->create([
        'domain' => 'shop-type-map.test',
        'is_active' => true,
    ]);

    $console = TypeStructure::query()->create([
        'type' => 'Console',
        'type_normalized' => 'console',
        'tags' => ['console', 'gaming console'],
        'structure' => ['producer' => 'any'],
    ]);

    // 1x1 transparent PNG
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+G2Z0AAAAASUVORK5CYII=', true);
    expect($png)->not->toBeFalse();
    Storage::disk('local')->put('screenshots/test/type-map.png', $png);

    $page = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop-type-map.test/product/1',
        'title' => 'Xbox Series X',
        'content_with_tags_purified' => '<main>В наявності <button>Купити</button></main>',
        'screenshot_path' => 'screenshots/test/type-map.png',
        'last_crawled_at' => now(),
    ]);

    $openAi = \Mockery::mock(LmStudioOpenApiService::class);
    $openAi->shouldReceive('isConfigured')->andReturnTrue();
    $openAi->shouldReceive('getBaseUrl')->andReturn('http://lmstudio.test');
    $openAi->shouldReceive('getModel')->andReturn('test-model');
    $openAi->shouldReceive('chatWithImage')
        ->once()
        ->andReturn([
            'content' => '{"is_product": true, "is_product_available": true, "product_type": "gaming_console"}',
            'model' => 'test-model',
        ]);
    app()->instance(LmStudioOpenApiService::class, $openAi);

    $exit = Artisan::call('page:product-type-detect', [
        '--limit' => 1,
        '--max-attempts' => 1,
    ]);

    expect($exit)->toBe(0);

    $page->refresh();
    expect($page->product_type_id)->toBe($console->id);
});





