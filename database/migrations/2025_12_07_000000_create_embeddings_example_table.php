<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Пример создания таблицы с векторными полями для работы с эмбэдингами.
     * Используется расширение pgvector для PostgreSQL.
     */
    public function up(): void
    {
        // Активируем расширение pgvector (если еще не активировано)
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('source')->nullable();
            
            // Векторное поле для эмбэдингов
            // 1536 измерений - стандарт для OpenAI text-embedding-ada-002
            // Для других моделей может потребоваться другая размерность:
            // - OpenAI text-embedding-3-small: 1536
            // - OpenAI text-embedding-3-large: 3072
            // - Cohere embed-multilingual-v3.0: 1024
            $table->vector('embedding', 1536);
            
            $table->timestamps();
        });

        // Создаем индекс для векторного поиска
        // IVFFlat - быстрый индекс для больших датасетов (рекомендуется для >10k записей)
        // lists - количество кластеров (обычно sqrt(количество_записей))
        DB::statement('
            CREATE INDEX embeddings_embedding_idx 
            ON embeddings 
            USING ivfflat (embedding vector_cosine_ops) 
            WITH (lists = 100)
        ');

        // Альтернативно можно использовать HNSW индекс (более точный, но медленнее):
        // DB::statement('
        //     CREATE INDEX embeddings_embedding_hnsw_idx 
        //     ON embeddings 
        //     USING hnsw (embedding vector_cosine_ops)
        // ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};

