<?php

use App\Http\Middleware\ResolveTelegramUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
        ]);

        $middleware->alias([
            'telegram.user' => ResolveTelegramUser::class,
        ]);
    })
    ->booting(function () {
        RateLimiter::for('telegram', function (Request $request) {
            $userId = $request->input('auth_user.id') ?? $request->ip();

            return Limit::perMinute(
                config('stock_alert.limits.telegram_rate_per_min', 30)
            )->by($userId);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
