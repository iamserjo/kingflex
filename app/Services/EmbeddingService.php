<?php

namespace App\Services;

use App\Models\Embedding;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с векторными эмбэдингами
 * 
 * Поддерживает:
 * - OpenAI API
 * - Другие провайдеры (легко расширяется)
 */
class EmbeddingService
{
    private const OPENAI_EMBEDDING_MODEL = 'text-embedding-3-small';
    private const EMBEDDING_DIMENSIONS = 1536;

    /**
     * Генерация эмбэдинга для текста через OpenAI API
     * 
     * @param string $text Текст для генерации эмбэдинга
     * @return array|null Массив чисел (вектор) или null в случае ошибки
     */
    public function generateEmbedding(string $text): ?array
    {
        $apiKey = config('services.openai.api_key');
        
        if (!$apiKey) {
            Log::warning('OpenAI API key not configured');
            return $this->generateMockEmbedding();
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/embeddings', [
                'model' => self::OPENAI_EMBEDDING_MODEL,
                'input' => $text,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['data'][0]['embedding'] ?? null;
            }

            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to generate embedding', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Создание эмбэдинга для документа и сохранение в базу
     * 
     * @param string $title Заголовок документа
     * @param string $content Содержимое документа
     * @param string|null $source Источник (опционально)
     * @return Embedding|null
     */
    public function createEmbedding(
        string $title,
        string $content,
        ?string $source = null
    ): ?Embedding {
        // Генерируем эмбэдинг для комбинации заголовка и содержимого
        $text = "{$title}\n\n{$content}";
        $vector = $this->generateEmbedding($text);

        if (!$vector) {
            return null;
        }

        return Embedding::create([
            'title' => $title,
            'content' => $content,
            'source' => $source,
            'embedding' => $vector,
        ]);
    }

    /**
     * Семантический поиск по базе эмбэдингов
     * 
     * @param string $query Поисковый запрос
     * @param int $limit Количество результатов
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function search(string $query, int $limit = 10)
    {
        $queryEmbedding = $this->generateEmbedding($query);

        if (!$queryEmbedding) {
            return collect([]);
        }

        return Embedding::findSimilar($queryEmbedding, $limit);
    }

    /**
     * Пакетная обработка текстов и создание эмбэдингов
     * 
     * @param array $documents Массив документов [['title' => '...', 'content' => '...'], ...]
     * @param string|null $source Общий источник для всех документов
     * @return int Количество созданных эмбэдингов
     */
    public function batchCreateEmbeddings(array $documents, ?string $source = null): int
    {
        $count = 0;

        foreach ($documents as $doc) {
            $embedding = $this->createEmbedding(
                $doc['title'] ?? 'Untitled',
                $doc['content'] ?? '',
                $source ?? ($doc['source'] ?? null)
            );

            if ($embedding) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Генерация mock эмбэдинга для тестирования
     * (когда OpenAI API недоступен)
     * 
     * @return array
     */
    private function generateMockEmbedding(): array
    {
        // Генерируем случайный вектор нужной размерности
        $vector = [];
        for ($i = 0; $i < self::EMBEDDING_DIMENSIONS; $i++) {
            $vector[] = (mt_rand(-1000, 1000) / 1000);
        }
        
        // Нормализуем вектор
        $magnitude = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));
        return array_map(fn($x) => $x / $magnitude, $vector);
    }

    /**
     * Обновление эмбэдинга для существующей записи
     * 
     * @param Embedding $embedding
     * @return bool
     */
    public function updateEmbedding(Embedding $embedding): bool
    {
        $text = "{$embedding->title}\n\n{$embedding->content}";
        $vector = $this->generateEmbedding($text);

        if (!$vector) {
            return false;
        }

        $embedding->embedding = $vector;
        return $embedding->save();
    }

    /**
     * Получить похожие документы для существующего эмбэдинга
     * 
     * @param Embedding $embedding
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findSimilarDocuments(Embedding $embedding, int $limit = 10)
    {
        return Embedding::where('id', '!=', $embedding->id)
            ->findSimilar($embedding->embedding, $limit);
    }

    /**
     * Вычислить косинусное сходство между двумя векторами
     * 
     * @param array $vector1
     * @param array $vector2
     * @return float
     */
    public function cosineSimilarity(array $vector1, array $vector2): float
    {
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }
}

