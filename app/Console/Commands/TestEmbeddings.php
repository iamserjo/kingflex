<?php

namespace App\Console\Commands;

use App\Models\Embedding;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;

class TestEmbeddings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'embeddings:test
                            {--create : Создать тестовые эмбэдинги}
                            {--search= : Поиск по запросу}
                            {--stats : Показать статистику}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Тестирование работы с векторными эмбэдингами и pgvector';

    /**
     * Execute the console command.
     */
    public function handle(EmbeddingService $embeddingService): int
    {
        $this->info('🔍 Тестирование pgvector и эмбэдингов');
        $this->newLine();

        // Проверка расширения pgvector
        if (!$this->checkPgVector()) {
            $this->error('❌ Расширение pgvector не установлено!');
            return Command::FAILURE;
        }

        $this->info('✅ Расширение pgvector активно');
        $this->newLine();

        if ($this->option('create')) {
            return $this->createTestEmbeddings($embeddingService);
        }

        if ($this->option('search')) {
            return $this->searchEmbeddings($embeddingService, $this->option('search'));
        }

        if ($this->option('stats')) {
            return $this->showStats();
        }

        $this->info('Используйте опции для работы с эмбэдингами:');
        $this->info('  --create     Создать тестовые эмбэдинги');
        $this->info('  --search="текст"  Поиск по запросу');
        $this->info('  --stats      Показать статистику');

        return Command::SUCCESS;
    }

    /**
     * Проверка установки pgvector
     */
    private function checkPgVector(): bool
    {
        try {
            $result = \DB::select("SELECT * FROM pg_extension WHERE extname = 'vector'");
            return count($result) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Создание тестовых эмбэдингов
     */
    private function createTestEmbeddings(EmbeddingService $embeddingService): int
    {
        $this->info('📝 Создание тестовых эмбэдингов...');
        
        $testDocuments = [
            [
                'title' => 'PostgreSQL и векторный поиск',
                'content' => 'PostgreSQL с расширением pgvector предоставляет мощные возможности для работы с векторными представлениями и семантического поиска.',
                'source' => 'test',
            ],
            [
                'title' => 'Работа с Redis в Laravel',
                'content' => 'Redis - это быстрое хранилище данных в памяти, идеально подходящее для кеширования и управления очередями в Laravel приложениях.',
                'source' => 'test',
            ],
            [
                'title' => 'Docker для разработки',
                'content' => 'Docker контейнеризация упрощает развертывание приложений и обеспечивает консистентность между окружениями разработки и production.',
                'source' => 'test',
            ],
            [
                'title' => 'Машинное обучение и AI',
                'content' => 'Современные модели машинного обучения используют векторные представления для понимания семантики текста и изображений.',
                'source' => 'test',
            ],
            [
                'title' => 'Web разработка с Laravel',
                'content' => 'Laravel - мощный PHP фреймворк, предоставляющий элегантный синтаксис и множество инструментов для быстрой разработки веб-приложений.',
                'source' => 'test',
            ],
        ];

        $bar = $this->output->createProgressBar(count($testDocuments));
        $bar->start();

        $created = 0;
        foreach ($testDocuments as $doc) {
            $embedding = $embeddingService->createEmbedding(
                $doc['title'],
                $doc['content'],
                $doc['source']
            );

            if ($embedding) {
                $created++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ Создано {$created} эмбэдингов");

        return Command::SUCCESS;
    }

    /**
     * Поиск по эмбэдингам
     */
    private function searchEmbeddings(EmbeddingService $embeddingService, string $query): int
    {
        $this->info("🔎 Поиск по запросу: \"{$query}\"");
        $this->newLine();

        $results = $embeddingService->search($query, 5);

        if ($results->isEmpty()) {
            $this->warn('Результаты не найдены');
            return Command::SUCCESS;
        }

        $this->info('📊 Найденные результаты:');
        $this->newLine();

        $tableData = [];
        foreach ($results as $result) {
            $similarity = 1 - $result->distance; // Конвертируем расстояние в схожесть
            $tableData[] = [
                'ID' => $result->id,
                'Заголовок' => $result->title,
                'Схожесть' => sprintf('%.4f', $similarity),
                'Создан' => $result->created_at->format('Y-m-d H:i'),
            ];
        }

        $this->table(
            ['ID', 'Заголовок', 'Схожесть', 'Создан'],
            $tableData
        );

        // Показываем детали первого результата
        $this->newLine();
        $this->info('📄 Детали самого похожего результата:');
        $this->line($results->first()->content);

        return Command::SUCCESS;
    }

    /**
     * Показать статистику
     */
    private function showStats(): int
    {
        $this->info('📊 Статистика по эмбэдингам:');
        $this->newLine();

        $stats = Embedding::getVectorStats();

        $this->info("Всего векторов: {$stats['total_vectors']}");
        $this->info("Размер таблицы: {$stats['table_size']}");
        $this->newLine();

        if (!empty($stats['indexes'])) {
            $this->info('Индексы:');
            foreach ($stats['indexes'] as $index) {
                $this->line("  • {$index->indexname}: {$index->index_size}");
            }
        }

        return Command::SUCCESS;
    }
}

