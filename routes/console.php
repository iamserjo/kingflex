<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Page Processing Schedule
|--------------------------------------------------------------------------
|
| Process pages using Playwright browser - extracts content, generates
| recap and embedding. Runs every 5 minutes, processing 5 pages at a time.
|
| To enable, add this to your crontab:
| * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
|
| Or in Docker:
| * * * * * docker-compose exec laravel.test php artisan schedule:run >> /dev/null 2>&1
|
*/

Schedule::command('page:process --limit=5')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/page-processor.log'))
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::info('✅ Scheduled page processing completed');
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('❌ Scheduled page processing failed');
    });
