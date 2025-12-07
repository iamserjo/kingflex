<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Модель для работы с векторными эмбэдингами
 * 
 * @property int $id
 * @property string $title
 * @property string $content
 * @property string|null $source
 * @property array $embedding Векторное представление контента
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Embedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'source',
        'embedding',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Поиск похожих документов по векторному представлению
     * 
     * @param array $queryEmbedding Векторное представление для поиска
     * @param int $limit Количество результатов
     * @param string $metric Метрика расстояния ('cosine', 'l2', 'inner_product')
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function findSimilar(
        array $queryEmbedding, 
        int $limit = 10,
        string $metric = 'cosine'
    ) {
        // Преобразуем массив в строку формата PostgreSQL vector
        $vectorString = '[' . implode(',', $queryEmbedding) . ']';
        
        // Выбираем оператор в зависимости от метрики
        $operator = match($metric) {
            'cosine' => '<=>', // Косинусное расстояние
            'l2' => '<->', // Евклидово расстояние (L2)
            'inner_product' => '<#>', // Отрицательное внутреннее произведение
            default => '<=>',
        };

        return self::select('*')
            ->selectRaw("embedding {$operator} ? as distance", [$vectorString])
            ->orderBy('distance')
            ->limit($limit)
            ->get();
    }

    /**
     * Установить векторное представление
     * 
     * @param array $vector
     * @return void
     */
    public function setEmbeddingAttribute(array $vector): void
    {
        $this->attributes['embedding'] = '[' . implode(',', $vector) . ']';
    }

    /**
     * Получить векторное представление в виде массива
     * 
     * @param string|null $value
     * @return array|null
     */
    public function getEmbeddingAttribute(?string $value): ?array
    {
        if (!$value) {
            return null;
        }

        // Убираем квадратные скобки и преобразуем в массив float
        $cleaned = trim($value, '[]');
        return array_map('floatval', explode(',', $cleaned));
    }

    /**
     * Поиск по тексту с использованием векторного представления
     * Требует предварительной генерации эмбэдинга для поискового запроса
     * 
     * @param string $query Поисковый запрос
     * @param callable $embeddingGenerator Функция для генерации эмбэдинга
     * @param int $limit Количество результатов
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function searchByText(
        string $query,
        callable $embeddingGenerator,
        int $limit = 10
    ) {
        $queryEmbedding = $embeddingGenerator($query);
        return self::findSimilar($queryEmbedding, $limit);
    }

    /**
     * Получить статистику по векторам в таблице
     * 
     * @return array
     */
    public static function getVectorStats(): array
    {
        $stats = DB::select("
            SELECT 
                COUNT(*) as total_vectors,
                pg_size_pretty(pg_total_relation_size('embeddings')) as table_size
            FROM embeddings
        ");

        $indexStats = DB::select("
            SELECT 
                indexname,
                pg_size_pretty(pg_relation_size(indexname::regclass)) as index_size
            FROM pg_indexes 
            WHERE tablename = 'embeddings'
        ");

        return [
            'total_vectors' => $stats[0]->total_vectors ?? 0,
            'table_size' => $stats[0]->table_size ?? '0 bytes',
            'indexes' => $indexStats,
        ];
    }
}

