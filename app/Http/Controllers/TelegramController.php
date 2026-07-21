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
        $responseText = $botService->handle($user, $text);

        // Kirim response via Telegram kalau token tersedia
        $botToken = config('stock_alert.telegram.bot_token');
        if ($botToken) {
            $telegram = new Api($botToken);
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $responseText,
                'parse_mode' => 'HTML',
            ]);
        }

        return response()->json(['ok' => true, 'response' => $responseText]);
    }
}
