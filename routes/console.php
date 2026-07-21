<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('stocks:fetch-prices')
    ->everyFiveMinutes()
    ->weekdays()
    ->between('9:00', '16:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(10)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler.log'));
