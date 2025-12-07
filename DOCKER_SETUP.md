# Docker Setup для MarketKing

## Основные компоненты

### PostgreSQL 18 с pgvector
- **Версия**: PostgreSQL 18 (последняя стабильная версия)
- **Образ**: `pgvector/pgvector:pg18`
- **Порт**: 5432
- **Возможности**:
  - Полная поддержка векторных представлений через расширение `pgvector`
  - Работа с эмбэдингами для AI/ML задач
  - Высокая производительность для векторного поиска
  - Последние улучшения производительности PostgreSQL 18

### Redis 7
- **Версия**: Redis 7 (Alpine Linux)
- **Порт**: 6379
- **Использование**:
  - Кеширование данных
  - Управление очередями
  - Хранение сессий

## Быстрый старт

### 1. Настройка окружения

Скопируйте пример конфигурации:
```bash
cp .env.example .env
```

Или настройте основные переменные вручную в `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=marketking
DB_USERNAME=postgres
DB_PASSWORD=password

REDIS_HOST=redis
REDIS_PORT=6379
```

### Примечание о хранении данных

**PostgreSQL данные** хранятся в директории проекта:
- `postgres/data/` - данные PostgreSQL 18
- Директория автоматически добавлена в `.gitignore`
- Удобно для бэкапов и управления данными

**Redis данные** хранятся в Docker volume:
- `sail-redis` - Named volume для Redis

### 2. Запуск контейнеров

```bash
# Запустить все сервисы
docker compose up -d

# Просмотр логов
docker compose logs -f

# Остановить все сервисы
docker compose down
```

### 3. Инициализация приложения

```bash
# Войти в контейнер приложения
docker compose exec laravel.test bash

# Установить зависимости
composer install

# Сгенерировать ключ приложения
php artisan key:generate

# Запустить миграции
php artisan migrate

# (Опционально) Заполнить базу тестовыми данными
php artisan db:seed
```

## Работа с PostgreSQL и pgvector

### Активация расширения pgvector

Расширение `pgvector` автоматически активируется при инициализации базы данных через скрипт:
`docker/pgsql/create-testing-database.sql`

### Использование векторов в Laravel

Пример создания таблицы с векторным полем:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('embeddings', function (Blueprint $table) {
    $table->id();
    $table->text('content');
    // Вектор из 1536 измерений (например, для OpenAI embeddings)
    $table->specificType('embedding', 'vector(1536)');
    $table->timestamps();
    
    // Индекс для быстрого векторного поиска
    $table->index('embedding', 'embedding_idx', 'ivfflat');
});
```

Пример работы с векторами:

```php
use Illuminate\Support\Facades\DB;

// Вставка вектора
DB::table('embeddings')->insert([
    'content' => 'Пример текста',
    'embedding' => '[0.1, 0.2, 0.3, ...]', // Массив из 1536 чисел
    'created_at' => now(),
    'updated_at' => now(),
]);

// Поиск ближайших векторов (косинусное расстояние)
$similar = DB::table('embeddings')
    ->select('*')
    ->selectRaw('embedding <=> ? as distance', ['[0.1, 0.2, 0.3, ...]'])
    ->orderBy('distance')
    ->limit(5)
    ->get();
```

### Операторы расстояния в pgvector

- `<->` - Евклидово расстояние (L2)
- `<#>` - Отрицательное внутреннее произведение
- `<=>` - Косинусное расстояние

## Работа с Redis

### Использование Redis для кеша

Убедитесь, что в `.env` установлено:
```env
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

### Подключение к Redis CLI

```bash
docker compose exec redis redis-cli

# Примеры команд
> PING
> KEYS *
> GET key_name
```

## Управление данными

### Резервное копирование PostgreSQL

#### Способ 1: SQL dump (рекомендуется для миграции между версиями)

```bash
# Создать бэкап
docker compose exec pgsql pg_dump -U postgres marketking > backup.sql

# Восстановить бэкап
docker compose exec -T pgsql psql -U postgres marketking < backup.sql
```

#### Способ 2: Копирование директории данных (быстрее для полного бэкапа)

```bash
# Остановить PostgreSQL
docker compose stop pgsql

# Создать архив данных
tar -czf postgres-backup-$(date +%Y%m%d).tar.gz postgres/data/

# Запустить PostgreSQL
docker compose start pgsql
```

#### Восстановление из директории данных

```bash
# Остановить контейнеры
docker compose down

# Восстановить данные
rm -rf postgres/data/*
tar -xzf postgres-backup-YYYYMMDD.tar.gz

# Запустить контейнеры
docker compose up -d
```

### Очистка данных

```bash
# Остановить и удалить все контейнеры с данными
docker compose down -v

# Пересоздать базу данных
docker compose up -d pgsql
docker compose exec laravel.test php artisan migrate:fresh --seed
```

## Полезные команды

```bash
# Проверка состояния контейнеров
docker compose ps

# Перезапуск конкретного сервиса
docker compose restart pgsql

# Просмотр логов конкретного сервиса
docker compose logs -f pgsql

# Выполнение Artisan команд
docker compose exec laravel.test php artisan [command]

# Доступ к консоли PostgreSQL
docker compose exec pgsql psql -U postgres -d marketking

# Проверка версии PostgreSQL и расширений
docker compose exec pgsql psql -U postgres -d marketking -c "SELECT version();"
docker compose exec pgsql psql -U postgres -d marketking -c "\dx"
```

## Доступ к сервисам

- **Приложение**: http://localhost
- **PostgreSQL**: localhost:5432
- **Redis**: localhost:6379

## Устранение проблем

### PostgreSQL не запускается

Проверьте логи:
```bash
docker compose logs pgsql
```

Убедитесь, что порт 5432 не занят:
```bash
lsof -i :5432
```

### Redis не работает

```bash
docker compose logs redis
docker compose exec redis redis-cli ping
```

### Проблемы с правами доступа

```bash
# Установить правильного владельца для файлов Laravel
docker compose exec laravel.test chown -R www-data:www-data /var/www/html/storage
docker compose exec laravel.test chmod -R 775 /var/www/html/storage
```

## Производительность

### Настройка PostgreSQL для векторов

Для оптимизации работы с большими объемами векторов, можно настроить параметры PostgreSQL через переменные окружения в `compose.yaml`:

```yaml
pgsql:
  environment:
    POSTGRES_INITDB_ARGS: "-c shared_buffers=256MB -c effective_cache_size=1GB"
```

### Индексы для векторного поиска

```sql
-- IVFFlat индекс (быстрее, но менее точный)
CREATE INDEX ON embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);

-- HNSW индекс (точнее, но медленнее для вставки)
CREATE INDEX ON embeddings USING hnsw (embedding vector_cosine_ops);
```

