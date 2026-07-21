<?php

use App\Http\Middleware\ResolveTelegramUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Telegram\Bot\Api;

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
            $userId = $request->input('message.from.id')
                ?? $request->input('callback_query.from.id')
                ?? $request->input('auth_user.id')
                ?? $request->ip();

            return Limit::perMinute(
                config('stock_alert.limits.telegram_rate_per_min', 30)
            )->by($userId);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('telegram/webhook')) {
                return null;
            }

            $chatId = $request->input('message.chat.id')
                ?? $request->input('callback_query.message.chat.id');

            if ($chatId && config('stock_alert.telegram.bot_token')) {
                try {
                    $telegram = new Api(config('stock_alert.telegram.bot_token'));
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => '⚠️ Kamu terlalu cepat. Coba lagi dalam 1 menit.',
                    ]);
                } catch (Exception $ex) {
                    Log::warning('Failed to send throttle message to Telegram', [
                        'chat_id' => $chatId,
                        'error' => $ex->getMessage(),
                    ]);
                }
            }

            return response()->json(['ok' => true]);
        });
    })->create();
