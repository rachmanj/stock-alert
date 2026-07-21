<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class ResolveTelegramUser
{
    public function handle(Request $request, Closure $next)
    {
        $update = $request->input('message') ?? $request->input('callback_query.message');

        if (! $update) {
            return $next($request);
        }

        $from = $update['from'] ?? ($update['callback_query']['from'] ?? null);
        $chat = $update['chat'] ?? null;

        if (! $from) {
            return $next($request);
        }

        $telegramId = $from['id'];
        $chatId = $chat['id'] ?? $telegramId;

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'telegram_username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'chat_id' => $chatId,
            ]
        );

        if ($user->wasRecentlyCreated === false) {
            $user->update([
                'telegram_username' => $from['username'] ?? $user->telegram_username,
                'first_name' => $from['first_name'] ?? $user->first_name,
                'chat_id' => $chatId,
            ]);
        }

        $request->merge(['auth_user' => $user]);

        return $next($request);
    }
}
