#!/bin/bash

# Скрипт для проверки работоспособности Docker сервисов MarketKing

echo "🔍 Проверка состояния Docker сервисов..."
echo ""

# Цвета для вывода
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Функция проверки сервиса
check_service() {
    local service_name=$1
    local status=$(docker compose ps --services --filter "status=running" | grep "^${service_name}$")
    
    if [ -n "$status" ]; then
        echo -e "${GREEN}✓${NC} $service_name: Запущен"
        return 0
    else
        echo -e "${RED}✗${NC} $service_name: Не запущен"
        return 1
    fi
}

# Проверка основных сервисов
echo "📦 Основные сервисы:"
check_service "laravel.test"
check_service "pgsql"
check_service "redis"
echo ""

# Проверка PostgreSQL и pgvector
echo "🐘 PostgreSQL:"
if docker compose exec -T pgsql psql -U postgres -d marketking -c "SELECT version();" > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} PostgreSQL: Подключение успешно"
    
    # Проверка pgvector
    pgvector_check=$(docker compose exec -T pgsql psql -U postgres -d marketking -c "\dx" | grep "vector")
    if [ -n "$pgvector_check" ]; then
        echo -e "${GREEN}✓${NC} pgvector: Расширение установлено"
    else
        echo -e "${RED}✗${NC} pgvector: Расширение не найдено"
    fi
else
    echo -e "${RED}✗${NC} PostgreSQL: Ошибка подключения"
fi
echo ""

# Проверка Redis
echo "🔴 Redis:"
if docker compose exec -T redis redis-cli ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} Redis: Работает корректно"
else
    echo -e "${RED}✗${NC} Redis: Не отвечает"
fi
echo ""

# Информация о портах
echo "🌐 Доступные порты:"
echo "   • Приложение: http://localhost:${APP_PORT:-80}"
echo "   • PostgreSQL: localhost:${FORWARD_DB_PORT:-5432}"
echo "   • Redis: localhost:${FORWARD_REDIS_PORT:-6379}"
echo ""

# Информация о томах
echo "💾 Docker тома:"
docker volume ls | grep "marketking" | awk '{print "   • " $2}'
echo ""

# Проверка миграций
echo "📊 Статус миграций:"
if docker compose exec -T laravel.test php artisan migrate:status > /dev/null 2>&1; then
    echo -e "${GREEN}✓${NC} Миграции: Можно запустить"
    echo -e "${YELLOW}ℹ${NC}  Для запуска: docker compose exec laravel.test php artisan migrate"
else
    echo -e "${YELLOW}⚠${NC}  Миграции: Требуется настройка приложения"
fi
echo ""

echo "✅ Проверка завершена!"

