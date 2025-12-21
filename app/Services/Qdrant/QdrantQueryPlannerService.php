<?php

declare(strict_types=1);

namespace App\Services\Qdrant;

use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;

/**
 * Builds UI field list from TypeStructure->structure and asks LLM to produce a Qdrant query plan.
 */
final class QdrantQueryPlannerService
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
    ) {}

    /**
     * @param array<string, mixed> $structure
     * @return array<int, array{path: string, type: 'string'|'number'|'boolean', label: string}>
     */
    public function buildUiFieldsFromStructure(array $structure): array
    {
        $out = [];
        $this->walkStructure($structure, '', $out);

        // prepend json_attributes. prefix (payload shape)
        foreach ($out as &$f) {
            $f['path'] = 'json_attributes.' . $f['path'];
        }
        unset($f);

        return $out;
    }

    /**
     * Ask LLM to build a Qdrant plan from query.
     *
     * We search ONLY by the vector fields stored in Qdrant:
     * - product_summary_specs
     * - product_abilities
     * - product_predicted_search_text
     *
     * No structure-based filters are generated here.
     *
     * @return array{query_text: string, limit: int, filters: array<int, array{path: string, op: string, value: mixed}>}|null
     */
    public function buildPlan(string $query, TypeStructure $type, array $uiFields): ?array
    {
        $system = (string) view('ai-prompts.qdrant-build-query-plan', [
            'type' => [
                'id' => $type->id,
                'type' => $type->type,
                'type_normalized' => $type->type_normalized,
                'tags' => $type->tags,
            ],
            'structure' => $type->structure ?? [],
            'uiFields' => [],
            'allowedOps' => ['eq', 'gte', 'lte', 'contains', 'starts_with'],
        ])->render();

        $options = [];
        $model = $this->queryGeneratorModel();
        if ($model !== null) {
            $options['model'] = $model;
        }

        $resp = $this->openRouter->chatJson(
            systemPrompt: $system,
            userMessage: $query,
            options: $options,
        );

        if (!is_array($resp)) {
            return null;
        }

        $queryText = $resp['query_text'] ?? null;
        $limit = $resp['limit'] ?? 20;
        $filters = $resp['filters'] ?? [];

        if (!is_string($queryText) || trim($queryText) === '') {
            $queryText = $query;
        }
        $queryText = trim($queryText);

        $limit = is_int($limit) ? $limit : (is_string($limit) && ctype_digit($limit) ? (int) $limit : 20);
        $limit = max(1, min(50, $limit));

        // We intentionally ignore filters for now (json_structure is ignored).
        $normalizedFilters = [];

        return [
            'query_text' => $queryText,
            'limit' => $limit,
            'filters' => $normalizedFilters,
        ];
    }

    public function queryGeneratorModel(): ?string
    {
        $model = trim((string) config('qdrant.query_generator_model', ''));
        return $model !== '' ? $model : null;
    }

    /**
     * Build Qdrant payload filter.
     *
     * @param array<int, array{path: string, type: 'string'|'number'|'boolean', label: string}> $uiFields
     * @param array<int, array{path?: mixed, op?: mixed, value?: mixed}> $requestedFilters
     * @return array<string, mixed>
     */
    public function buildQdrantFilter(int $typeStructureId, array $uiFields, array $requestedFilters): array
    {
        $must = [];

        // Always constrain by type
        $must[] = [
            'key' => 'product_type_id',
            'match' => ['value' => $typeStructureId],
        ];

        return ['must' => $must];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapFilterToQdrantCondition(string $path, string $op, mixed $value): ?array
    {
        return match ($op) {
            'eq' => [
                'key' => $path,
                'match' => ['value' => $value],
            ],
            'contains' => is_string($value) ? [
                'key' => $path,
                'match' => ['text' => $value],
            ] : null,
            'starts_with' => is_string($value) ? [
                'key' => $path,
                // Qdrant doesn't guarantee prefix match without index config; we approximate via text match.
                'match' => ['text' => $value],
            ] : null,
            'gte' => (is_int($value) || is_float($value)) ? [
                'key' => $path,
                'range' => ['gte' => $value],
            ] : null,
            'lte' => (is_int($value) || is_float($value)) ? [
                'key' => $path,
                'range' => ['lte' => $value],
            ] : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array{path: string, type: 'string'|'number'|'boolean', label: string}> $out
     */
    private function walkStructure(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $path = $prefix === '' ? $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                // If associative array, recurse. If list, treat as string field (freeform).
                if (!array_is_list($value)) {
                    $this->walkStructure($value, $path, $out);
                    continue;
                }

                $out[] = [
                    'path' => $path,
                    'type' => 'string',
                    'label' => $path,
                ];
                continue;
            }

            $type = match (true) {
                is_int($value), is_float($value) => 'number',
                is_bool($value) => 'boolean',
                default => 'string',
            };

            $out[] = [
                'path' => $path,
                'type' => $type,
                'label' => $path,
            ];
        }
    }
}


