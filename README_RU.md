# MarketKing - AI-Ready Laravel приложение

## 🚀 Описание

Современное Laravel 11 приложение с поддержкой AI/ML возможностей через векторные эмбэдинги.

## 📦 Технологический стек

- **Backend**: PHP 8.4 + Laravel 11
- **База данных**: PostgreSQL 18 с расширением pgvector
- **Кеширование**: Redis 7
- **Контейнеризация**: Docker Compose

## ✨ Ключевые возможности

### 🧠 Векторные эмбэдинги и AI
- **pgvector** - расширение PostgreSQL для работы с векторами
- Поддержка векторов до 16,000 измерений
- Семантический поиск
- Интеграция с OpenAI API
- Готовые модели и сервисы для работы с эмбэдингами

### ⚡️ Производительность
- Redis для кеширования
- Redis для очередей
- Оптимизированные индексы для векторного поиска
- PostgreSQL 18 с последними улучшениями

## 🎯 Быстрый старт

### Требования
- Docker Desktop
- Make (опционально, но рекомендуется)

### Установка

```bash
# 1. Клонировать репозиторий
git clone <repository-url>
cd marketking

# 2. Настроить окружение
cp .env.example .env
# Отредактируйте .env при необходимости

# 3. Запустить Docker контейнеры
make up
# или без Make:
docker compose up -d

# 4. Установить зависимости и выполнить миграции
make setup
# или без Make:
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate

# 5. Проверить работоспособность
./docker-healthcheck.sh
```

Приложение доступно по адресу: **http://localhost**

## 🔧 Основные команды

### С использованием Makefile

```bash
make help              # Показать все команды
make up                # Запустить контейнеры
make down              # Остановить контейнеры
make shell             # Войти в контейнер приложения
make psql              # Подключиться к PostgreSQL
make redis             # Подключиться к Redis CLI
make logs              # Просмотр логов
make migrate           # Запустить миграции
make fresh             # Пересоздать БД (ВНИМАНИЕ: удалит данные!)
make test              # Запустить тесты
make healthcheck       # Проверка здоровья сервисов
```

### Без Makefile

```bash
docker compose up -d                              # Запустить
docker compose down                               # Остановить
docker compose exec laravel.test bash            # Shell
docker compose exec pgsql psql -U postgres -d marketking  # PostgreSQL
docker compose exec redis redis-cli              # Redis
docker compose logs -f                           # Логи
```

## 🧪 Работа с векторными эмбэдингами

### Тестирование pgvector

```bash
# Создать тестовые эмбэдинги
docker compose exec laravel.test php artisan embeddings:test --create

# Семантический поиск
docker compose exec laravel.test php artisan embeddings:test --search="машинное обучение"

# Статистика по векторам
docker compose exec laravel.test php artisan embeddings:test --stats
```

### Использование в коде

```php
use App\Services\EmbeddingService;

$service = app(EmbeddingService::class);

// Создание эмбэдинга
$embedding = $service->createEmbedding(
    title: 'Заголовок документа',
    content: 'Текст документа',
    source: 'knowledge-base'
);

// Семантический поиск
$results = $service->search('поисковый запрос', limit: 10);

// Поиск похожих документов
$similar = $service->findSimilarDocuments($embedding, limit: 5);
```

## 🌐 Доступные сервисы

| Сервис | Порт | Доступ |
|--------|------|--------|
| Laravel | 80 | http://localhost |
| PostgreSQL 18 | 5432 | localhost:5432 |
| Redis 7 | 6379 | localhost:6379 |

### Учетные данные по умолчанию

**PostgreSQL:**
- Database: `marketking`
- Username: `postgres`
- Password: `password`

**Redis:**
- Host: `redis`
- Port: `6379`
- Password: (нет)

## 📚 Документация

- **[QUICK_START.md](QUICK_START.md)** - Быстрый старт за 3 шага
- **[DOCKER_SETUP.md](DOCKER_SETUP.md)** - Подробное руководство по Docker
- **[EMBEDDINGS_GUIDE.md](EMBEDDINGS_GUIDE.md)** - Работа с векторными эмбэдингами
- **[LATEST_CHANGES.md](LATEST_CHANGES.md)** - Последние изменения

## 🔐 Настройка OpenAI (опционально)

Для использования настоящих AI эмбэдингов добавьте в `.env`:

```env
OPENAI_API_KEY=sk-your-api-key-here
OPENAI_ORGANIZATION=org-your-org-id  # опционально
```

Без API ключа сервис будет использовать mock-генератор для тестирования.

## 🏗️ Структура проекта

```
marketking/
├── app/
│   ├── Models/
│   │   └── Embedding.php          # Модель для векторов
│   ├── Services/
│   │   └── EmbeddingService.php   # Сервис для AI
│   └── Console/Commands/
│       └── TestEmbeddings.php     # Тестирование
├── database/
│   └── migrations/
│       └── *_create_embeddings_example_table.php
├── docker/
│   └── pgsql/
│       └── create-testing-database.sql
├── compose.yaml                   # Docker Compose конфиг
├── Makefile                       # Удобные команды
├── docker-healthcheck.sh          # Проверка здоровья
└── [документация]
```

## 🛠️ Разработка

### Запуск тестов

```bash
make test
# или
make pest
```

### Очистка кеша

```bash
make cache-clear
```

### Оптимизация для production

```bash
make optimize
```

### Работа с очередями

```bash
make queue-work    # Запустить обработчик
make queue-listen  # Прослушивание
```

## 🐛 Устранение проблем

### Порты заняты

Измените порты в `.env`:

```env
APP_PORT=8080
FORWARD_DB_PORT=54320
FORWARD_REDIS_PORT=63790
```

### Проблемы с правами доступа

```bash
docker compose exec laravel.test chown -R www-data:www-data storage
docker compose exec laravel.test chmod -R 775 storage
```

### Пересоздать базу данных

```bash
make fresh  # ВНИМАНИЕ: Удалит все данные!
```

### Проверка расширения pgvector

```bash
docker compose exec pgsql psql -U postgres -d marketking -c "\dx"
```

## 📊 Примеры использования векторов

### Создание таблицы с векторами

```php
Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('content');
    $table->vector('embedding', 1536); // OpenAI embedding size
    $table->timestamps();
});

// Создание индекса для поиска
DB::statement('
    CREATE INDEX documents_embedding_idx 
    ON documents 
    USING ivfflat (embedding vector_cosine_ops) 
    WITH (lists = 100)
');
```

### Поиск похожих документов

```php
use Illuminate\Support\Facades\DB;

$queryVector = $embeddingService->generateEmbedding('поисковый запрос');

$results = DB::table('embeddings')
    ->select('*')
    ->selectRaw('embedding <=> ? as distance', [$queryVector])
    ->orderBy('distance')
    ->limit(10)
    ->get();
```

## 🤝 Вклад в проект

1. Fork проекта
2. Создайте feature ветку (`git checkout -b feature/AmazingFeature`)
3. Commit изменения (`git commit -m 'Add some AmazingFeature'`)
4. Push в ветку (`git push origin feature/AmazingFeature`)
5. Откройте Pull Request

## 📝 Лицензия

[Укажите вашу лицензию]

## 📞 Поддержка

Для получения помощи:
- Используйте `make help` для списка команд
- Запустите `./docker-healthcheck.sh` для диагностики
- Изучите документацию в папке проекта

---

**Сделано с ❤️ и AI**

