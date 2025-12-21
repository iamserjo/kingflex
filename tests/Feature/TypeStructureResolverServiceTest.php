<?php

declare(strict_types=1);

use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\TypeStructure\TypeStructureResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('openrouter.embedding_dimensions', 3);
    config()->set('qdrant.host', 'http://qdrant.test');
    config()->set('qdrant.collection', 'pages');
    config()->set('qdrant.vector_size', 3);
});

test('type resolver infers type from qdrant top results (no hardcoded heuristics)', function () {
    $phone = TypeStructure::query()->create([
        'type' => 'Phone',
        'type_normalized' => 'phone',
        'tags' => ['phone', 'телефон', 'смартфон'],
        'structure' => ['producer' => 'any', 'ram' => ['size' => 0]],
    ]);

    $speaker = TypeStructure::query()->create([
        'type' => 'Flash Disk',
        'type_normalized' => 'flash_disk',
        'tags' => ['flash_disk', 'флешка', 'usb flash', 'накопитель'],
        'structure' => ['producer' => 'any', 'capacity' => ['size' => 0]],
    ]);

    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('createEmbedding')->once()->andReturn([0.1, 0.2, 0.3]);
    // LLM fallback should not be called if Qdrant provides signal.
    $openRouter->shouldNotReceive('chatJson');
    app()->instance(OpenRouterService::class, $openRouter);

    Http::fake(function (Request $request) use ($phone, $speaker) {
        $url = $request->url();
        if ($url === 'http://qdrant.test/collections/pages' && $request->method() === 'GET') {
            return Http::response([], 200);
        }
        if ($url === 'http://qdrant.test/collections/pages/points/search' && $request->method() === 'POST') {
            return Http::response([
                'result' => [
                    ['id' => 1, 'score' => 0.91, 'payload' => ['product_type_id' => $phone->id]],
                    ['id' => 2, 'score' => 0.85, 'payload' => ['product_type_id' => $phone->id]],
                    ['id' => 3, 'score' => 0.80, 'payload' => ['product_type_id' => $speaker->id]],
                ],
                'status' => 'ok',
                'time' => 0.01,
            ], 200);
        }
        return Http::response('not faked', 500);
    });

    $resolver = app(TypeStructureResolverService::class);
    $id = $resolver->resolveTypeStructureId('Instax Mini 12');

    expect($id)->toBe($phone->id);
});

test('type resolver falls back to LLM when qdrant search has no signal', function () {
    $camera = TypeStructure::query()->create([
        'type' => 'Camera',
        'type_normalized' => 'camera',
        'tags' => ['camera', 'камера', 'фотоаппарат'],
        'structure' => ['producer' => 'any'],
    ]);

    TypeStructure::query()->create([
        'type' => 'Speaker',
        'type_normalized' => 'speaker',
        'tags' => ['speaker', 'колонка', 'динамик'],
        'structure' => ['producer' => 'any'],
    ]);

    $openRouter = Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('createEmbedding')->once()->andReturn([0.1, 0.2, 0.3]);
    $openRouter->shouldReceive('chatJson')->once()->andReturn(['type_structure_id' => $camera->id]);
    app()->instance(OpenRouterService::class, $openRouter);

    Http::fake(function (Request $request) {
        $url = $request->url();
        if ($url === 'http://qdrant.test/collections/pages' && $request->method() === 'GET') {
            return Http::response([], 200);
        }
        if ($url === 'http://qdrant.test/collections/pages/points/search' && $request->method() === 'POST') {
            return Http::response([
                'result' => [],
                'status' => 'ok',
                'time' => 0.01,
            ], 200);
        }
        return Http::response('not faked', 500);
    });

    $resolver = app(TypeStructureResolverService::class);
    $id = $resolver->resolveTypeStructureId('Instax Mini 12');

    expect($id)->toBe($camera->id);
});


