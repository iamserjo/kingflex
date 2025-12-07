# Руководство по работе с векторными эмбэдингами в MarketKing

## Обзор

Проект настроен для работы с векторными представлениями (embeddings) через PostgreSQL с расширением pgvector. Это позволяет реализовать семантический поиск, рекомендательные системы и другие AI-функции.

## Компоненты

### 1. База данных: PostgreSQL 17 + pgvector

**Возможности:**
- Хранение векторов высокой размерности (до 16,000 измерений)
- Быстрый поиск ближайших соседей (k-NN)
- Поддержка различных метрик расстояния
- Индексы IVFFLAT и HNSW для оптимизации поиска

**Метрики расстояния:**
- `<->` - Евклидово расстояние (L2)
- `<#>` - Отрицательное внутреннее произведение
- `<=>` - Косинусное расстояние (рекомендуется для большинства случаев)

### 2. Модель: `App\Models\Embedding`

Предоставляет удобный интерфейс для работы с векторами:

```php
use App\Models\Embedding;

// Создание эмбэдинга
$embedding = Embedding::create([
    'title' => 'Заголовок документа',
    'content' => 'Содержимое документа',
    'embedding' => $vectorArray, // Массив из 1536 чисел
]);

// Поиск похожих документов
$similar = Embedding::findSimilar($queryVector, limit: 10);

// Статистика
$stats = Embedding::getVectorStats();
```

### 3. Сервис: `App\Services\EmbeddingService`

Интеграция с OpenAI и другими провайдерами:

```php
use App\Services\EmbeddingService;

$service = app(EmbeddingService::class);

// Создание эмбэдинга
$embedding = $service->createEmbedding(
    title: 'Название',
    content: 'Текст документа',
    source: 'optional-source'
);

// Семантический поиск
$results = $service->search('поисковый запрос', limit: 5);

// Пакетная обработка
$count = $service->batchCreateEmbeddings($documents);
```

## Настройка

### 1. Конфигурация OpenAI (опционально)

Добавьте в `.env`:

```env
OPENAI_API_KEY=sk-your-api-key-here
OPENAI_ORGANIZATION=org-your-org-id  # опционально
```

**Без OpenAI API:**
Сервис автоматически сгенерирует mock-векторы для тестирования.

### 2. Запуск миграций

```bash
# Через Makefile
make migrate

# Или напрямую
docker compose exec laravel.test php artisan migrate
```

Это создаст таблицу `embeddings` с полем `vector(1536)` и оптимизированными индексами.

## Использование

### Тестирование через Artisan команду

```bash
# Проверка pgvector и создание тестовых данных
docker compose exec laravel.test php artisan embeddings:test --create

# Семантический поиск
docker compose exec laravel.test php artisan embeddings:test --search="машинное обучение"

# Статистика
docker compose exec laravel.test php artisan embeddings:test --stats
```

### Примеры кода

#### Создание эмбэдингов для документов

```php
use App\Services\EmbeddingService;

$service = app(EmbeddingService::class);

$documents = [
    [
        'title' => 'Введение в AI',
        'content' => 'Искусственный интеллект трансформирует индустрию...',
    ],
    [
        'title' => 'Machine Learning основы',
        'content' => 'Машинное обучение использует алгоритмы...',
    ],
];

$created = $service->batchCreateEmbeddings($documents, source: 'knowledge-base');

echo "Создано {$created} эмбэдингов";
```

#### Семантический поиск

```php
use App\Services\EmbeddingService;

$service = app(EmbeddingService::class);

// Поиск по естественному языку
$results = $service->search('как работает нейронная сеть?', limit: 5);

foreach ($results as $doc) {
    $similarity = 1 - $doc->distance;
    echo "{$doc->title} (схожесть: " . round($similarity * 100, 2) . "%)\n";
    echo "{$doc->content}\n\n";
}
```

#### Поиск похожих документов

```php
use App\Models\Embedding;
use App\Services\EmbeddingService;

$document = Embedding::find(1);
$service = app(EmbeddingService::class);

$similar = $service->findSimilarDocuments($document, limit: 10);

foreach ($similar as $doc) {
    echo "- {$doc->title}\n";
}
```

#### Вычисление схожести

```php
use App\Services\EmbeddingService;

$service = app(EmbeddingService::class);

$embedding1 = Embedding::find(1);
$embedding2 = Embedding::find(2);

$similarity = $service->cosineSimilarity(
    $embedding1->embedding,
    $embedding2->embedding
);

echo "Косинусное сходство: " . round($similarity * 100, 2) . "%";
```

## Оптимизация производительности

### Индексы

#### IVFFlat (быстрее, подходит для больших датасетов)

```sql
CREATE INDEX embeddings_embedding_idx 
ON embeddings 
USING ivfflat (embedding vector_cosine_ops) 
WITH (lists = 100);
```

**Рекомендации:**
- `lists` обычно устанавливается как `sqrt(количество_записей)`
- Лучше для > 10,000 записей
- Требует "разогрева" индекса (VACUUM ANALYZE)

#### HNSW (точнее, медленнее для вставки)

```sql
CREATE INDEX embeddings_embedding_hnsw_idx 
ON embeddings 
USING hnsw (embedding vector_cosine_ops);
```

**Рекомендации:**
- Более точный поиск
- Медленнее для INSERT операций
- Лучше для статичных датасетов

### Настройка PostgreSQL для векторов

В `compose.yaml` можно добавить оптимизации:

```yaml
pgsql:
  environment:
    POSTGRES_INITDB_ARGS: >
      -c shared_buffers=512MB
      -c effective_cache_size=2GB
      -c maintenance_work_mem=256MB
      -c work_mem=64MB
```

### Стратегии поиска

#### Точный поиск (медленнее, точнее)

```php
$results = Embedding::select('*')
    ->selectRaw('embedding <=> ? as distance', [$vectorString])
    ->orderBy('distance')
    ->limit(10)
    ->get();
```

#### Быстрый поиск с префильтром

```php
$results = Embedding::where('created_at', '>', now()->subDays(30))
    ->select('*')
    ->selectRaw('embedding <=> ? as distance', [$vectorString])
    ->orderBy('distance')
    ->limit(10)
    ->get();
```

## Размерности векторов для разных моделей

| Модель | Размерность | Примечания |
|--------|------------|-----------|
| OpenAI text-embedding-3-small | 1536 | Рекомендуется, хороший баланс |
| OpenAI text-embedding-3-large | 3072 | Более точные, но больше |
| OpenAI text-embedding-ada-002 | 1536 | Предыдущая версия |
| Cohere embed-multilingual-v3.0 | 1024 | Поддержка многих языков |
| Sentence-BERT | 768 | Открытая модель |

### Изменение размерности

Для использования другой размерности, измените миграцию:

```php
// Вместо vector(1536)
$table->vector('embedding', 3072); // Для OpenAI large модели
```

## Кейсы использования

### 1. База знаний с семантическим поиском

```php
// Индексация документов
$service->batchCreateEmbeddings($knowledgeBase);

// Поиск ответов на вопросы
$answers = $service->search("Как сбросить пароль?");
```

### 2. Рекомендательная система

```php
// Найти похожие товары/статьи
$similar = $service->findSimilarDocuments($currentItem, limit: 5);
```

### 3. Дедупликация контента

```php
// Проверка на дубликаты
$duplicates = Embedding::findSimilar($newDocVector, limit: 5);
if ($duplicates->first()->distance < 0.05) {
    echo "Возможный дубликат найден!";
}
```

### 4. Категоризация документов

```php
// Создание векторов для категорий
$categories = [
    'tech' => $service->generateEmbedding("технологии программирование"),
    'business' => $service->generateEmbedding("бизнес финансы"),
];

// Классификация нового документа
$docVector = $service->generateEmbedding($newDoc->content);
$bestCategory = null;
$bestSimilarity = 0;

foreach ($categories as $name => $catVector) {
    $similarity = $service->cosineSimilarity($docVector, $catVector);
    if ($similarity > $bestSimilarity) {
        $bestSimilarity = $similarity;
        $bestCategory = $name;
    }
}
```

## Мониторинг и отладка

### Проверка статуса pgvector

```sql
SELECT * FROM pg_extension WHERE extname = 'vector';
```

### Статистика по индексам

```sql
SELECT 
    schemaname,
    tablename,
    indexname,
    pg_size_pretty(pg_relation_size(indexname::regclass)) as index_size
FROM pg_indexes
WHERE tablename = 'embeddings';
```

### Анализ производительности поиска

```sql
EXPLAIN ANALYZE
SELECT *, embedding <=> '[0.1, 0.2, ...]' as distance
FROM embeddings
ORDER BY distance
LIMIT 10;
```

## Troubleshooting

### Ошибка: "type vector does not exist"

```bash
# Войти в PostgreSQL
docker compose exec pgsql psql -U postgres -d marketking

# Создать расширение
CREATE EXTENSION vector;
```

### Медленный поиск

1. Проверьте наличие индексов
2. Выполните `VACUUM ANALYZE embeddings;`
3. Увеличьте `shared_buffers` в PostgreSQL
4. Используйте префильтры для сужения области поиска

### Ошибки с OpenAI API

Сервис автоматически переключится на mock-генератор, если API недоступен. Проверьте логи:

```bash
docker compose logs laravel.test
```

## Дополнительные ресурсы

- [pgvector GitHub](https://github.com/pgvector/pgvector)
- [OpenAI Embeddings Guide](https://platform.openai.com/docs/guides/embeddings)
- [PostgreSQL Vector Operations](https://github.com/pgvector/pgvector#vector-operations)

## Команды для быстрого старта

```bash
# Запуск окружения
make up

# Миграции
make migrate

# Создание тестовых эмбэдингов
make shell
php artisan embeddings:test --create

# Тестовый поиск
php artisan embeddings:test --search="ваш запрос"

# Статистика
php artisan embeddings:test --stats
```

