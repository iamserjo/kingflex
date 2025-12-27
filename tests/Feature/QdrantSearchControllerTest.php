<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('openrouter.embedding_dimensions', 3);
    config()->set('qdrant.host', 'http://qdrant.test');
    config()->set('qdrant.collection', 'pages');
    config()->set('qdrant.vector_size', 3);
    config()->set('qdrant.query_generator_model', 'openai/gpt-5.1-codex-max');
});

test('GET /qdrant/stats returns stored/total counts', function () {
    $domain = Domain::query()->create(['domain' => 'shop.test', 'is_active' => true]);

    Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/p1',
        'url_hash' => hash('sha256', 'https://shop.test/p1'),
        'is_product' => true,
        'is_product_available' => true,
        'qdstored_at' => now(),
    ]);
    Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/p2',
        'url_hash' => hash('sha256', 'https://shop.test/p2'),
        'is_product' => true,
        'is_product_available' => true,
        'qdstored_at' => null,
    ]);

    $res = $this->getJson('/qdrant/stats');

    $res->assertOk()
        ->assertJson([
            'stored_count' => 1,
            'total_products_count' => 2,
        ]);
});

test('POST /qdrant/plan selects type and returns ui fields + qdrant plan', function () {
    $type = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['телефон', 'phone'],
        'structure' => [
            'producer' => 'any',
            'ram' => ['size' => 0],
        ],
    ]);

    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('chatJson')->andReturnUsing(function (string $systemPrompt, string $userMessage, array $options = []) use ($type) {
        if (str_contains($systemPrompt, 'product type selector')) {
            return ['type_structure_id' => $type->id];
        }
        if (str_contains($systemPrompt, 'Qdrant query planner')) {
            expect($options['model'] ?? null)->toBe('openai/gpt-5.1-codex-max');
            return [
                'query_text' => $userMessage,
                'limit' => 10,
                'filters' => [
                    ['path' => 'json_attributes.producer', 'op' => 'contains', 'value' => 'Apple'],
                    ['path' => 'json_attributes.ram.size', 'op' => 'gte', 'value' => 8],
                ],
            ];
        }
        return null;
    });
    app()->instance(OpenRouterService::class, $openRouter);

    $res = $this->postJson('/qdrant/plan', ['query' => 'apple 8gb']);

    $res->assertOk()
        ->assertJsonPath('type_structure.id', $type->id)
        ->assertJsonPath('qdrant_plan.query_text', 'apple 8gb')
        ->assertJsonPath('qdrant_plan_model', 'openai/gpt-5.1-codex-max')
        ->assertJsonPath('qdrant_plan.limit', 10);
});

test('POST /qdrant/search returns only pages that are qdstored_at != null', function () {
    $domain = Domain::query()->create(['domain' => 'shop.test', 'is_active' => true]);

    $type = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['телефон', 'phone'],
        'structure' => [
            'producer' => 'any',
            'ram' => ['size' => 0],
        ],
    ]);

    $storedPage = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/p1',
        'title' => 'Phone A',
        'url_hash' => hash('sha256', 'https://shop.test/p1'),
        'is_product' => true,
        'is_product_available' => true,
        'product_type_id' => $type->id,
        'json_attributes' => ['producer' => 'Apple', 'ram' => ['size' => 8]],
        'attributes_extracted_at' => now(),
        'qdstored_at' => now(),
    ]);

    $notStoredPage = Page::query()->create([
        'domain_id' => $domain->id,
        'url' => 'https://shop.test/p2',
        'title' => 'Phone B',
        'url_hash' => hash('sha256', 'https://shop.test/p2'),
        'is_product' => true,
        'is_product_available' => true,
        'product_type_id' => $type->id,
        'json_attributes' => ['producer' => 'Apple', 'ram' => ['size' => 8]],
        'attributes_extracted_at' => now(),
        'qdstored_at' => null,
    ]);

    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('createEmbedding')->once()->andReturn([0.1, 0.2, 0.3]);
    app()->instance(OpenRouterService::class, $openRouter);

    Http::fake(function (Request $request) use ($storedPage, $notStoredPage) {
        $url = $request->url();

        if ($url === 'http://qdrant.test/collections/pages' && $request->method() === 'GET') {
            return Http::response([], 200);
        }

        if ($url === 'http://qdrant.test/collections/pages/points/search' && $request->method() === 'POST') {
            return Http::response([
                'result' => [
                    ['id' => $storedPage->id, 'score' => 0.91, 'payload' => ['page_id' => $storedPage->id]],
                    ['id' => $notStoredPage->id, 'score' => 0.90, 'payload' => ['page_id' => $notStoredPage->id]],
                ],
                'status' => 'ok',
                'time' => 0.01,
            ], 200);
        }

        return Http::response('not faked', 500);
    });

    $res = $this->postJson('/qdrant/search', [
        'type_structure_id' => $type->id,
        'query_text' => 'apple 8gb',
        'limit' => 10,
        'filters' => [
            ['path' => 'json_attributes.producer', 'op' => 'contains', 'value' => 'Apple'],
        ],
    ]);

    $res->assertOk();

    $json = $res->json();
    expect($json['results'])->toHaveCount(1);
    expect($json['results'][0]['page_id'])->toBe($storedPage->id);
});


