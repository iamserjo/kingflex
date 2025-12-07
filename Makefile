.PHONY: help up down restart logs shell psql redis build clean migrate seed fresh test healthcheck

# Цвета для вывода
BLUE := \033[0;34m
GREEN := \033[0;32m
NC := \033[0m # No Color

help: ## Показать эту справку
	@echo "$(BLUE)Доступные команды:$(NC)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-15s$(NC) %s\n", $$1, $$2}'

up: ## Запустить все Docker контейнеры
	docker compose up -d
	@echo "$(GREEN)✓ Контейнеры запущены$(NC)"
	@echo "$(BLUE)Приложение доступно на: http://localhost$(NC)"

down: ## Остановить все Docker контейнеры
	docker compose down
	@echo "$(GREEN)✓ Контейнеры остановлены$(NC)"

restart: ## Перезапустить все контейнеры
	docker compose restart
	@echo "$(GREEN)✓ Контейнеры перезапущены$(NC)"

logs: ## Показать логи всех контейнеров
	docker compose logs -f

logs-app: ## Показать логи приложения
	docker compose logs -f laravel.test

logs-db: ## Показать логи PostgreSQL
	docker compose logs -f pgsql

logs-redis: ## Показать логи Redis
	docker compose logs -f redis

shell: ## Войти в контейнер приложения
	docker compose exec laravel.test bash

psql: ## Подключиться к PostgreSQL
	docker compose exec pgsql psql -U postgres -d marketking

redis: ## Подключиться к Redis CLI
	docker compose exec redis redis-cli

build: ## Пересобрать все контейнеры
	docker compose build --no-cache
	@echo "$(GREEN)✓ Контейнеры пересобраны$(NC)"

clean: ## Остановить контейнеры и удалить тома (ВНИМАНИЕ: удалит все данные!)
	@echo "$(BLUE)⚠️  ВНИМАНИЕ: Это удалит все данные в базе данных и Redis!$(NC)"
	@read -p "Вы уверены? [y/N] " -n 1 -r; \
	echo; \
	if [[ $$REPLY =~ ^[Yy]$$ ]]; then \
		docker compose down -v; \
		echo "$(GREEN)✓ Контейнеры и тома удалены$(NC)"; \
	fi

install: ## Установить зависимости Composer
	docker compose exec laravel.test composer install
	@echo "$(GREEN)✓ Зависимости установлены$(NC)"

migrate: ## Запустить миграции
	docker compose exec laravel.test php artisan migrate
	@echo "$(GREEN)✓ Миграции выполнены$(NC)"

seed: ## Заполнить базу данных тестовыми данными
	docker compose exec laravel.test php artisan db:seed
	@echo "$(GREEN)✓ База данных заполнена$(NC)"

fresh: ## Пересоздать базу данных и запустить миграции с seed
	docker compose exec laravel.test php artisan migrate:fresh --seed
	@echo "$(GREEN)✓ База данных пересоздана$(NC)"

test: ## Запустить тесты
	docker compose exec laravel.test php artisan test

pest: ## Запустить Pest тесты
	docker compose exec laravel.test ./vendor/bin/pest

healthcheck: ## Проверить состояние всех сервисов
	@./docker-healthcheck.sh

tinker: ## Открыть Laravel Tinker
	docker compose exec laravel.test php artisan tinker

cache-clear: ## Очистить кеш приложения
	docker compose exec laravel.test php artisan cache:clear
	docker compose exec laravel.test php artisan config:clear
	docker compose exec laravel.test php artisan route:clear
	docker compose exec laravel.test php artisan view:clear
	@echo "$(GREEN)✓ Кеш очищен$(NC)"

optimize: ## Оптимизировать приложение для production
	docker compose exec laravel.test php artisan config:cache
	docker compose exec laravel.test php artisan route:cache
	docker compose exec laravel.test php artisan view:cache
	@echo "$(GREEN)✓ Приложение оптимизировано$(NC)"

queue-work: ## Запустить обработчик очередей
	docker compose exec laravel.test php artisan queue:work

queue-listen: ## Запустить прослушивание очередей
	docker compose exec laravel.test php artisan queue:listen

ps: ## Показать статус контейнеров
	docker compose ps

stats: ## Показать статистику использования ресурсов
	docker stats --no-stream

check-pgvector: ## Проверить установку pgvector расширения
	@echo "$(BLUE)Проверка pgvector:$(NC)"
	@docker compose exec pgsql psql -U postgres -d marketking -c "\dx" | grep vector || echo "$(RED)pgvector не установлен$(NC)"

setup: up install migrate ## Полная установка проекта (запуск, установка зависимостей, миграции)
	docker compose exec laravel.test php artisan key:generate
	@echo "$(GREEN)✓ Проект успешно настроен!$(NC)"
	@echo "$(BLUE)Приложение доступно на: http://localhost$(NC)"

