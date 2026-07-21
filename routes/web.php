<?php

use App\Http\Controllers\TelegramController;
use App\Http\Middleware\ResolveTelegramUser;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/telegram/webhook', [TelegramController::class, 'handle'])
    ->middleware(['throttle:telegram', ResolveTelegramUser::class]);
