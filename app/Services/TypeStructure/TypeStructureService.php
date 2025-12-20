<?php

declare(strict_types=1);

namespace App\Services\TypeStructure;

use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class TypeStructureService
{
    private const int TAGS_LIMIT = 20;

    private const int LOCK_SECONDS = 30;

    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * Get type structure (without "tags") for the given type/tag.
     *
     * @return array<string, mixed>
     */
    public function get(string $typeOrTag): array
    {
        return $this->getWithTags($typeOrTag)['structure'];
    }

    /**
     * Find existing type structure id for the given type/tag (DB only, no AI generation).
     */
    public function findExistingId(string $typeOrTag): ?int
    {
        $original = trim($typeOrTag);
        $normalized = $this->normalizeTag($original);

        if ($normalized === '') {
            return null;
        }

        return $this->findExisting($normalized)?->id;
    }

    /**
     * Find existing type structure id for the given type/tag or generate & store it (with tags/structure).
     *
     * Primary path:
     * - If exists in DB -> return id
     * - Else -> call getWithTags() which uses ai-prompts.type-structure + OpenRouter and persists
     *
     * Fallback path (only when AI generation is not possible):
     * - Create a minimal record to avoid leaving FK null.
     */
    public function findOrCreateId(string $typeOrTag): ?int
    {
        $original = trim($typeOrTag);
        $normalized = $this->normalizeTag($original);

        if ($normalized === '') {
            return null;
        }

        $existing = $this->findExisting($normalized);
        if ($existing !== null) {
            return $existing->id;
        }

        // Generate + store (AI) if possible.
        $generated = $this->getWithTags($original);
        if (($generated['source'] ?? 'none') !== 'none') {
            return $this->findExistingId($original);
        }

        // Fallback: minimal record.
        $row = TypeStructure::query()->updateOrCreate(
            ['type_normalized' => $normalized],
            [
                'type' => $original,
                'tags' => [$normalized],
                'structure' => [],
            ],
        );

        return $row->id;
    }

    /**
     * Get type structure and tags for the given type/tag.
     *
     * @return array{type: string, structure: array<string, mixed>, tags: array<int, string>, source: 'db'|'ai'|'none'}
     */
    public function getWithTags(string $typeOrTag): array
    {
        $original = trim($typeOrTag);
        $normalized = $this->normalizeTag($original);

        if ($normalized === '') {
            return [
                'type' => $original,
                'structure' => [],
                'tags' => [],
                'source' => 'none',
            ];
        }

        $existing = $this->findExisting($normalized);
        if ($existing !== null) {
            return [
                'type' => $existing->type,
                'structure' => (array) ($existing->structure ?? []),
                'tags' => $this->normalizeTags((array) ($existing->tags ?? []), $normalized),
                'source' => 'db',
            ];
        }

        // Prevent duplicate OpenRouter calls for the same normalized type/tag.
        $lockKey = 'type_structure:' . $normalized;

        try {
            /** @var Lock $lock */
            $lock = Cache::lock($lockKey, self::LOCK_SECONDS);
            return $lock->block(10, function () use ($original, $normalized) {
                // Re-check inside the lock.
                $existingInsideLock = $this->findExisting($normalized);
                if ($existingInsideLock !== null) {
                    return [
                        'type' => $existingInsideLock->type,
                        'structure' => (array) ($existingInsideLock->structure ?? []),
                        'tags' => $this->normalizeTags((array) ($existingInsideLock->tags ?? []), $normalized),
                        'source' => 'db',
                    ];
                }

                return $this->generateAndStore($original, $normalized);
            });
        } catch (\Throwable $e) {
            // Cache store may not support locks; fall back without locking.
            Log::warning('[TypeStructure] Cache lock failed, falling back to unlocked flow', [
                'type' => $original,
                'normalized' => $normalized,
                'error' => $e->getMessage(),
            ]);

            $existingFallback = $this->findExisting($normalized);
            if ($existingFallback !== null) {
                return [
                    'type' => $existingFallback->type,
                    'structure' => (array) ($existingFallback->structure ?? []),
                    'tags' => $this->normalizeTags((array) ($existingFallback->tags ?? []), $normalized),
                    'source' => 'db',
                ];
            }

            return $this->generateAndStore($original, $normalized);
        }
    }

    private function findExisting(string $normalizedTypeOrTag): ?TypeStructure
    {
        return TypeStructure::query()
            ->where('type_normalized', $normalizedTypeOrTag)
            ->orWhereJsonContains('tags', $normalizedTypeOrTag)
            ->first();
    }

    /**
     * @return array{type: string, structure: array<string, mixed>, tags: array<int, string>, source: 'ai'|'none'}
     */
    private function generateAndStore(string $original, string $normalized): array
    {
        if (!$this->openRouter->isConfigured()) {
            Log::warning('[TypeStructure] OpenRouter is not configured; cannot generate structure', [
                'type' => $original,
                'normalized' => $normalized,
            ]);

            return [
                'type' => $original,
                'structure' => [],
                'tags' => [],
                'source' => 'none',
            ];
        }

        $systemPrompt = (string) view('ai-prompts.type-structure', ['type' => $original])->render();
        $userMessage = "тип товара: {$original}";

        $model = trim((string) env('TYPE_STRUCTURE_MODEL', ''));

        $options = [
            'temperature' => 0.2,
        ];
        if ($model !== '') {
            $options['model'] = $model;
        }

        $result = $this->openRouter->chatJson($systemPrompt, $userMessage, $options);

        if ($result === null) {
            Log::warning('[TypeStructure] OpenRouter returned null JSON', [
                'type' => $original,
                'normalized' => $normalized,
            ]);

            return [
                'type' => $original,
                'structure' => [],
                'tags' => [],
                'source' => 'none',
            ];
        }

        $tags = $this->extractTags($result, $normalized);
        $structure = $this->extractStructure($result);

        TypeStructure::query()->updateOrCreate(
            ['type_normalized' => $normalized],
            [
                'type' => $original,
                'tags' => $tags,
                'structure' => $structure,
            ],
        );

        return [
            'type' => $original,
            'structure' => $structure,
            'tags' => $tags,
            'source' => 'ai',
        ];
    }

    /**
     * Normalize a single tag/type string for matching.
     */
    private function normalizeTag(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');

        return $value;
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, string>
     */
    private function normalizeTags(array $tags, string $ensureIncludesNormalized): array
    {
        $normalized = [];

        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $t = $this->normalizeTag($tag);
            if ($t === '') {
                continue;
            }
            $normalized[] = $t;
        }

        $normalized[] = $ensureIncludesNormalized;

        $normalized = array_values(array_unique($normalized));
        $normalized = array_slice($normalized, 0, self::TAGS_LIMIT);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, string>
     */
    private function extractTags(array $result, string $ensureIncludesNormalized): array
    {
        $tags = Arr::get($result, 'tags', []);

        // Accept both ["a","b"] and {"a":1,"b":2} shapes defensively.
        if (is_array($tags) && Arr::isAssoc($tags)) {
            $tags = array_keys($tags);
        }

        if (!is_array($tags)) {
            $tags = [];
        }

        /** @var array<int, mixed> $tags */
        return $this->normalizeTags($tags, $ensureIncludesNormalized);
    }

    /**
     * Extract structure JSON without the "tags" key.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function extractStructure(array $result): array
    {
        unset($result['tags']);

        // Ensure we return an object-like array (associative). If model returned a list, keep empty.
        if (!is_array($result) || !Arr::isAssoc($result)) {
            return [];
        }

        return $result;
    }
}






