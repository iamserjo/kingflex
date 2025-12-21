<?php

declare(strict_types=1);

namespace App\Services\TypeStructure;

use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Qdrant\QdrantClient;

/**
 * Uses LLM to select the best matching TypeStructure for a user query.
 */
final class TypeStructureResolverService
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
        private readonly QdrantClient $qdrant,
    ) {}

    public function resolveTypeStructureId(string $query): ?int
    {
        $typesCollection = TypeStructure::query()
            ->orderBy('id')
            ->get(['id', 'type', 'type_normalized', 'tags']);

        // 1) Data-driven: infer type by searching Qdrant first (no hardcoded heuristics).
        $qdrantTypeId = $this->inferTypeFromQdrant($query, $typesCollection->all());
        if ($qdrantTypeId !== null) {
            return $qdrantTypeId;
        }

        $storedCounts = Page::query()
            ->whereNotNull('qdstored_at')
            ->whereNotNull('product_type_id')
            ->selectRaw('product_type_id, COUNT(*)::int as cnt')
            ->groupBy('product_type_id')
            ->pluck('cnt', 'product_type_id')
            ->all();

        $types = $typesCollection
            ->map(fn (TypeStructure $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'type_normalized' => $t->type_normalized,
                'tags' => $t->tags,
                'stored_count' => (int) ($storedCounts[$t->id] ?? 0),
            ])->all();

        $system = (string) view('ai-prompts.qdrant-select-type-structure', [
            'types' => $types,
        ])->render();

        $resp = $this->openRouter->chatJson(
            systemPrompt: $system,
            userMessage: $query,
        );

        if (!is_array($resp)) {
            return null;
        }

        $id = $resp['type_structure_id'] ?? null;
        if (is_int($id) && $id > 0) {
            return $id;
        }
        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        return null;
    }

    /**
     * Try to infer type id from Qdrant search results:
     * - Embed the query
     * - Search across the whole collection (no type filter)
     * - Pick product_type_id that dominates top results by weighted score
     *
     * @param array<int, TypeStructure> $types
     */
    private function inferTypeFromQdrant(string $query, array $types): ?int
    {
        if (!$this->qdrant->isConfigured() || !$this->openRouter->isConfigured()) {
            return null;
        }

        try {
            $this->qdrant->ensureCollection();
        } catch (\Throwable) {
            return null;
        }

        $embedding = $this->openRouter->createEmbedding($query);
        if ($embedding === null || $embedding === []) {
            return null;
        }

        $targetDims = max(1, (int) config('openrouter.embedding_dimensions', count($embedding)));
        if (count($embedding) > $targetDims) {
            $embedding = array_slice($embedding, 0, $targetDims);
        }

        $search = $this->qdrant->searchPoints(
            collection: $this->qdrant->defaultCollection(),
            vector: $embedding,
            filter: null,
            limit: 30,
            withPayload: true,
        );

        $rows = $search['result'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $weights = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = $row['payload'] ?? null;
            if (!is_array($payload)) {
                continue;
            }
            $typeId = $payload['product_type_id'] ?? null;
            if (is_string($typeId) && ctype_digit($typeId)) {
                $typeId = (int) $typeId;
            }
            if (!is_int($typeId) || $typeId <= 0) {
                continue;
            }

            $score = $row['score'] ?? 0.0;
            $score = is_numeric($score) ? (float) $score : 0.0;

            $weights[$typeId] = ($weights[$typeId] ?? 0.0) + max(0.0, $score);
        }

        if ($weights === []) {
            return null;
        }

        // Only allow ids that exist in TypeStructure list.
        $valid = array_fill_keys(array_map(static fn (TypeStructure $t) => (int) $t->id, $types), true);

        arsort($weights);
        foreach ($weights as $typeId => $w) {
            if (isset($valid[$typeId])) {
                return (int) $typeId;
            }
        }

        return null;
    }
}


