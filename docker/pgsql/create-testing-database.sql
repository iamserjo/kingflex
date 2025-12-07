-- Создание тестовой базы данных для Laravel
CREATE DATABASE testing;

-- Подключение к тестовой базе данных и создание расширений
\c testing;

-- Активация расширения pgvector для работы с векторными представлениями
CREATE EXTENSION IF NOT EXISTS vector;

-- Возвращаемся к основной базе данных
\c marketking;

-- Активация расширения pgvector для основной базы данных
CREATE EXTENSION IF NOT EXISTS vector;

