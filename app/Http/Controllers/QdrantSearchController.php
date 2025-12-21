<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\TypeStructure;
use App\Services\OpenRouter\OpenRouterService;
use App\Services\Qdrant\QdrantClient;
use App\Services\Qdrant\QdrantQueryPlannerService;
use App\Services\TypeStructure\TypeStructureResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class QdrantSearchController extends Controller
{
    public function __construct(
        private readonly QdrantClient $qdrant,
        private readonly OpenRouterService $openRouter,
        private readonly TypeStructureResolverService $typeResolver,
        private readonly QdrantQueryPlannerService $planner,
    ) {}

    public function index(): View
    {
        return view('qdrant');
    }

    public function stats(): JsonResponse
    {
        $stored = Page::query()
            ->where('is_product', true)
            ->whereNotNull('qdstored_at')
            ->count();

        $total = Page::query()
            ->where('is_product', true)
            ->count();

        return response()->json([
            'stored_count' => $stored,
            'total_products_count' => $total,
        ]);
    }

    public function plan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $query = (string) $validated['query'];

        if (!$this->openRouter->isConfigured()) {
            return response()->json(['error' => 'OpenRouter is not configured'], 422);
        }

        $typeId = $this->typeResolver->resolveTypeStructureId($query);
        if ($typeId === null) {
            return response()->json([
                'error' => 'Could not determine product type from query',
                'type_structure' => null,
            ], 422);
        }

        $type = TypeStructure::query()->find($typeId);
        if ($type === null) {
            return response()->json([
                'error' => 'Type structure not found',
                'type_structure' => null,
            ], 422);
        }

        // json_structure filters are ignored; plan only produces query_text + limit.
        $plan = $this->planner->buildPlan(query: $query, type: $type, uiFields: []);

        if ($plan === null) {
            return response()->json([
                'error' => 'Failed to generate Qdrant plan',
            ], 422);
        }

        return response()->json([
            'source_query' => $query,
            'type_structure' => [
                'id' => $type->id,
                'type' => $type->type,
                'type_normalized' => $type->type_normalized,
                'tags' => $type->tags,
            ],
            'structure' => null,
            'ui_fields' => [],
            'qdrant_plan_model' => $this->planner->queryGeneratorModel(),
            'qdrant_plan' => $plan,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type_structure_id' => ['required', 'integer'],
            'query_text' => ['required', 'string', 'min:1', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'filters' => ['nullable', 'array', 'max:30'],
            'filters.*.path' => ['required_with:filters', 'string', 'max:200'],
            'filters.*.op' => ['required_with:filters', 'string', 'in:eq,gte,lte,contains,starts_with'],
            'filters.*.value' => ['nullable'],
        ]);

        $typeId = (int) $validated['type_structure_id'];
        $type = TypeStructure::query()->find($typeId);
        if ($type === null) {
            return response()->json(['error' => 'Type structure not found'], 422);
        }

        if (!$this->openRouter->isConfigured()) {
            return response()->json(['error' => 'OpenRouter is not configured'], 422);
        }
        if (!$this->qdrant->isConfigured()) {
            return response()->json(['error' => 'Qdrant is not configured'], 422);
        }

        $this->qdrant->ensureCollection();

        $queryText = (string) $validated['query_text'];
        $limit = (int) ($validated['limit'] ?? 20);
        // json_structure filters are ignored; search is only by vector fields in Qdrant.
        $filters = [];

        $embedding = $this->openRouter->createEmbedding($queryText);
        if ($embedding === null || $embedding === []) {
            return response()->json(['error' => 'Failed to create embedding for query'], 422);
        }

        $targetDims = max(1, (int) config('openrouter.embedding_dimensions', count($embedding)));
        if (count($embedding) > $targetDims) {
            $embedding = array_slice($embedding, 0, $targetDims);
        }

        $qdrantFilter = $this->planner->buildQdrantFilter(
            typeStructureId: $type->id,
            uiFields: [],
            requestedFilters: $filters,
        );

        $qdrantRequest = [
            'collection' => $this->qdrant->defaultCollection(),
            'vector_dims' => count($embedding),
            'limit' => $limit,
            'filter' => $qdrantFilter,
            'query_text' => $queryText,
        ];

        $qdrantResults = $this->qdrant->searchPoints(
            collection: $this->qdrant->defaultCollection(),
            vector: $embedding,
            filter: $qdrantFilter,
            limit: $limit,
            withPayload: true,
        );

        $ids = array_values(array_filter(array_map(
            static fn (array $row) => $row['id'] ?? null,
            $qdrantResults['result'] ?? []
        ), static fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id))));
        $ids = array_map(static fn ($id) => (int) $id, $ids);

        // DB gate: only show results that are confirmed stored.
        $pagesById = Page::query()
            ->whereIn('id', $ids)
            ->whereNotNull('qdstored_at')
            ->get(['id', 'url', 'title', 'qdstored_at'])
            ->keyBy('id');

        $results = [];
        foreach (($qdrantResults['result'] ?? []) as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            if ($id === null || !$pagesById->has($id)) {
                continue;
            }

            /** @var Page $page */
            $page = $pagesById->get($id);

            $results[] = [
                'page_id' => $id,
                'score' => (float) ($row['score'] ?? 0.0),
                'url' => (string) $page->url,
                'title' => $page->title,
                'payload' => $row['payload'] ?? null,
            ];
        }

        return response()->json([
            'qdrant_request' => $qdrantRequest,
            'qdrant_response' => [
                'result_count' => is_array($qdrantResults['result'] ?? null) ? count($qdrantResults['result']) : 0,
                'status' => $qdrantResults['status'] ?? null,
                'time' => $qdrantResults['time'] ?? null,
            ],
            'results' => $results,
        ]);
    }
}


