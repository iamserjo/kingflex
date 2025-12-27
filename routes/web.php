<?php

use App\Http\Controllers\ConsultantChatController;
use App\Http\Controllers\Admin\PageAttributesController;
use App\Http\Controllers\Admin\PageProductTypeController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Admin\PageScreenshotController;
use App\Http\Controllers\Admin\AiLogsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SearchController::class, 'home'])->name('home');
Route::post('/search', [SearchController::class, 'search'])->name('search');

Route::prefix('admin')->group(function () {
    Route::get('/pages/attributes', [PageAttributesController::class, 'index'])
        ->name('admin.pages.attributes');

    Route::get('/pages/product_type', [PageProductTypeController::class, 'index'])
        ->name('admin.pages.product_type');

    Route::get('/pages/{page}/screenshot', [PageScreenshotController::class, 'show'])
        ->name('admin.pages.screenshot');

    Route::get('/logs/ai', [AiLogsController::class, 'index'])
        ->name('admin.logs.ai');
});

Route::prefix('v1/chatboot')->group(function () {
    Route::get('/', [ConsultantChatController::class, 'index'])->name('consultant.index');
    Route::post('/message', [ConsultantChatController::class, 'message'])
        ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
        ->name('consultant.message');
});

