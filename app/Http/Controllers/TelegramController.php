<?php

namespace App\Http\Controllers;

use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Telegram\Bot\Api;

class TelegramController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $user = $request->get('auth_user');
        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'unauthorized']);
        }

        $update = $request->all();
        $message = $update['message'] ?? ($update['callback_query']['message'] ?? null);
        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $text = $message['text'] ?? '';
        $chatId = $message['chat']['id'] ?? $user->chat_id;

        $botService = app(TelegramBotService::class);
        $response = $botService->handle($user, $text);

        $telegram = new Api(config('stock_alert.telegram.bot_token'));
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $response,
            'parse_mode' => 'HTML',
        ]);

        return response()->json(['ok' => true]);
    }
}
