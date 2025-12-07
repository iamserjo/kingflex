# MarketKing - Быстрый старт

## 🚀 Запуск проекта за 3 шага

### 1. Настройка окружения

```bash
cp .env.docker.example .env
# Отредактируйте .env при необходимости
```

### 2. Запуск Docker контейнеров

```bash
make up
# или
docker compose up -d
```

### 3. Инициализация приложения

```bash
make setup
# или вручную:
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate
```

## ✅ Проверка работоспособности

```bash
./docker-healthcheck.sh
# или
make healthcheck
```

## 🌐 Доступ к сервисам

- **Приложение**: http://localhost
- **PostgreSQL**: localhost:5432
- **Redis**: localhost:6379

## 📦 Технологии

- **PHP 8.4** + Laravel 11
- **PostgreSQL 18** с pgvector для векторов и эмбэдингов
- **Redis 7** для кеширования и очередей

## 🔧 Полезные команды

```bash
make help              # Показать все доступные команды
make shell             # Войти в контейнер приложения
make logs              # Просмотр логов
make psql              # Подключиться к PostgreSQL
make redis             # Подключиться к Redis CLI
make migrate           # Запустить миграции
make test              # Запустить тесты
```

## 📚 Подробная документация

- [DOCKER_SETUP.md](DOCKER_SETUP.md) - Полное руководство по Docker
- [EMBEDDINGS_GUIDE.md](EMBEDDINGS_GUIDE.md) - Работа с векторными эмбэдингами и AI

## 🧪 Тестирование векторных эмбэдингов

```bash
# Создать тестовые эмбэдинги
docker compose exec laravel.test php artisan embeddings:test --create

# Семантический поиск
docker compose exec laravel.test php artisan embeddings:test --search="ваш запрос"

# Статистика
docker compose exec laravel.test php artisan embeddings:test --stats
```

## 🛠 Устранение проблем

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
make fresh  # Внимание: удалит все данные!
```

## 💡 Дополнительная помощь

Используйте `make help` для просмотра всех доступных команд.

