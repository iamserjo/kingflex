<?php

use App\Http\Controllers\ChatBootController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SearchController::class, 'home'])->name('home');
Route::post('/search', [SearchController::class, 'search'])->name('search');

Route::get('/chatboot', [ChatBootController::class, 'index'])->name('chatboot');
Route::post('/chatboot/message', [ChatBootController::class, 'message'])->name('chatboot.message');

