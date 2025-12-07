<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Crawler Schedule
|--------------------------------------------------------------------------
|
| The crawler runs automatically every hour via Laravel's task scheduler.
|
| To enable, add this to your crontab:
| * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
|
| Or in Docker:
| * * * * * docker-compose exec laravel.test php artisan schedule:run >> /dev/null 2>&1
|
| Logs are written to:
| - storage/logs/laravel.log (main application log)
| - storage/logs/crawler.log (crawler console output)
|
*/

Schedule::command('crawl:update')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/crawler.log'))
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::info('✅ Scheduled crawl update completed successfully');
    })
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('❌ Scheduled crawl update failed');
    });
