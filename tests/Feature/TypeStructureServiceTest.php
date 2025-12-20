<?php

use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\TypeStructure\TypeStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('type structure service returns structure from database by tag match', function () {
    $row = TypeStructure::query()->create([
        'type' => 'Ноутбук',
        'type_normalized' => 'ноутбук',
        'tags' => ['ноутбук', 'laptop', 'notebook'],
        'structure' => [
            'producer' => 'acer',
            'model' => 'aspire 5',
            'ram' => ['size' => 16, 'type' => 'ddr4', 'humanSize' => '16GB'],
        ],
    ]);

    /** @var OpenRouterService $openRouter */
    $openRouter = \Mockery::mock(OpenRouterService::class);
    $openRouter->shouldNotReceive('chatJson');
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    app()->instance(OpenRouterService::class, $openRouter);

    $service = app(TypeStructureService::class);

    $result = $service->getWithTags('Laptop');

    expect($result['source'])->toBe('db')
        ->and($result['type'])->toBe($row->type)
        ->and($result['structure'])->toMatchArray($row->structure)
        ->and($result['tags'])->toContain('laptop');
});

test('type structure service generates and stores structure when missing', function () {
    /** @var OpenRouterService $openRouter */
    $openRouter = \Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            'tags' => ['phone', 'телефон', 'смартфон', 'мобільний телефон'],
            'producer' => 'apple',
            'model' => 'iphone 15',
            'color' => 'black',
            'ram' => ['size' => 8, 'humanSize' => '8GB'],
        ]);
    app()->instance(OpenRouterService::class, $openRouter);

    $service = app(TypeStructureService::class);

    $result = $service->getWithTags('Телефон');

    expect($result['source'])->toBe('ai')
        ->and($result['structure'])->toMatchArray([
            'producer' => 'apple',
            'model' => 'iphone 15',
            'color' => 'black',
            'ram' => ['size' => 8, 'humanSize' => '8GB'],
        ])
        ->and($result['structure'])->not->toHaveKey('tags')
        ->and($result['tags'])->toContain('телефон')
        ->and($result['tags'])->toContain('phone');

    $stored = TypeStructure::query()->where('type_normalized', 'телефон')->first();
    expect($stored)->not->toBeNull()
        ->and($stored->structure)->toMatchArray($result['structure'])
        ->and($stored->tags)->toContain('телефон')
        ->and($stored->tags)->toContain('phone');
});

test('type structure service reuses existing type when AI tags overlap (no duplicates)', function () {
    $existing = TypeStructure::query()->create([
        'type' => 'Телевизор',
        'type_normalized' => 'televizor',
        'tags' => ['televizor', 'телевизор', 'tv'],
        'structure' => ['producer' => 'samsung'],
    ]);

    /** @var OpenRouterService $openRouter */
    $openRouter = \Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            'tags' => ['телевизор', 'televizor', 'televizer'],
            'producer' => 'samsung',
            'model' => 'qn85b',
        ]);
    app()->instance(OpenRouterService::class, $openRouter);

    $service = app(TypeStructureService::class);

    $result = $service->getWithTags('televizer');

    // Should not create a new row; should reuse existing.
    expect(TypeStructure::query()->count())->toBe(1)
        ->and($result['source'])->toBe('db')
        ->and($service->findExistingId('televizer'))->toBe($existing->id);

    $existing->refresh();
    // Tags should be merged to include the new alias.
    expect($existing->tags)->toContain('televizer');
});

test('type structure service reuses existing by tags and stores requested alias tag so lookups work', function () {
    $existing = TypeStructure::query()->create([
        'type' => 'Console',
        'type_normalized' => 'console',
        'tags' => ['console', 'gaming console'],
        'structure' => ['producer' => 'any'],
    ]);

    /** @var OpenRouterService $openRouter */
    $openRouter = \Mockery::mock(OpenRouterService::class);
    $openRouter->shouldReceive('isConfigured')->andReturnTrue();
    $openRouter->shouldReceive('chatJson')
        ->once()
        ->andReturn([
            // AI recognizes it as console and doesn't include the alias itself.
            'tags' => ['console', 'gaming console'],
            'producer' => 'any',
        ]);
    app()->instance(OpenRouterService::class, $openRouter);

    $service = app(TypeStructureService::class);

    $id = $service->findOrCreateId('gaming_console');
    expect($id)->toBe($existing->id);

    $existing->refresh();
    expect($existing->tags)->toContain('gaming_console');

    // And now the alias can be resolved from DB.
    expect($service->findExistingId('gaming_console'))->toBe($existing->id);
});






