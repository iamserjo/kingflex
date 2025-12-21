<?php

declare(strict_types=1);

namespace App\Services\Qdrant;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Minimal Qdrant HTTP client for managing collections and upserting points.
 *
 * Docs:
 * - https://qdrant.tech/documentation/concepts/collections/
 * - https://qdrant.tech/documentation/concepts/points/
 */
final class QdrantClient
{
    /**
     * @return non-empty-string
     */
    public function host(): string
    {
        $host = rtrim(trim((string) config('qdrant.host', '')), '/');
        if ($host === '') {
            throw new RuntimeException('Qdrant host is not configured (qdrant.host / QDRANT_HOST).');
        }

        return $host;
    }

    public function defaultCollection(): string
    {
        return (string) config('qdrant.collection', 'pages');
    }

    public function vectorSize(): int
    {
        return max(1, (int) config('qdrant.vector_size', 2000));
    }

    /**
     * @return 'Cosine'|'Dot'|'Euclid'|'Manhattan'|string
     */
    public function distance(): string
    {
        return (string) config('qdrant.distance', 'Cosine');
    }

    public function isConfigured(): bool
    {
        return trim((string) config('qdrant.host', '')) !== '';
    }

    /**
     * Ensure collection exists (create if missing).
     */
    public function ensureCollection(?string $collection = null, ?int $vectorSize = null, ?string $distance = null): void
    {
        $collection = $collection ?? $this->defaultCollection();
        $vectorSize = $vectorSize ?? $this->vectorSize();
        $distance = $distance ?? $this->distance();

        $resp = $this->request()->get("/collections/{$collection}");

        if ($resp->status() === 200) {
            return;
        }

        if ($resp->status() !== 404) {
            throw new RuntimeException("Qdrant error when checking collection '{$collection}': HTTP {$resp->status()} - {$this->safeBody($resp)}");
        }

        $create = $this->request()->put("/collections/{$collection}", [
            'vectors' => [
                'size' => $vectorSize,
                'distance' => $distance,
            ],
        ]);

        if (!$create->successful()) {
            throw new RuntimeException("Failed to create Qdrant collection '{$collection}': HTTP {$create->status()} - {$this->safeBody($create)}");
        }

        Log::info('✅ [Qdrant] Collection created', [
            'collection' => $collection,
            'vector_size' => $vectorSize,
            'distance' => $distance,
        ]);
    }

    /**
     * Upsert points into a collection.
     *
     * @param array<int, array{id: int|string, vector: array<float>, payload?: array<string, mixed>}> $points
     */
    public function upsertPoints(string $collection, array $points, bool $wait = true): void
    {
        $qs = $wait ? '?wait=true' : '';

        $resp = $this->request()->put("/collections/{$collection}/points{$qs}", [
            'points' => $points,
        ]);

        if (!$resp->successful()) {
            throw new RuntimeException("Failed to upsert points to Qdrant collection '{$collection}': HTTP {$resp->status()} - {$this->safeBody($resp)}");
        }
    }

    /**
     * Vector search points in Qdrant.
     *
     * @param array<string, mixed>|null $filter
     * @return array<string, mixed>
     */
    public function searchPoints(
        string $collection,
        array $vector,
        ?array $filter = null,
        int $limit = 20,
        bool $withPayload = true,
    ): array {
        $body = [
            'vector' => array_values($vector),
            'limit' => max(1, min(50, $limit)),
            'with_payload' => $withPayload,
        ];

        if ($filter !== null) {
            $body['filter'] = $filter;
        }

        $resp = $this->request()->post("/collections/{$collection}/points/search", $body);

        if (!$resp->successful()) {
            throw new RuntimeException("Failed to search points in Qdrant collection '{$collection}': HTTP {$resp->status()} - {$this->safeBody($resp)}");
        }

        $json = $resp->json();

        return is_array($json) ? $json : [];
    }

    private function request(): PendingRequest
    {
        $req = Http::baseUrl($this->host())
            ->timeout((int) config('qdrant.timeout', 30))
            ->retry(3, 250, throw: false);

        $apiKey = trim((string) config('qdrant.api_key', ''));
        if ($apiKey !== '') {
            // Qdrant uses 'api-key' header
            $req = $req->withHeaders(['api-key' => $apiKey]);
        }

        return $req;
    }

    private function safeBody(Response $response): string
    {
        $body = trim((string) $response->body());
        if ($body === '') {
            return '[empty body]';
        }

        return mb_substr($body, 0, 1000, 'UTF-8');
    }
}


