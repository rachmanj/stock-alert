<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\PriceAlert;
use App\Models\User;
use Telegram\Bot\Api;

class NotificationService
{
    public function sendAlert(User $user, PriceAlert $alert, float $currentPrice): void
    {
        $directionText = $alert->direction === 'bawah' ? 'turun ke' : 'naik ke';

        if (str_ends_with(strtoupper($alert->ticker), '.JK')) {
            $targetFormatted = 'Rp '.number_format($alert->target_price, 0, ',', '.');
            $currentFormatted = 'Rp '.number_format($currentPrice, 0, ',', '.');
        } else {
            $targetFormatted = '$'.number_format($alert->target_price, 2);
            $currentFormatted = '$'.number_format($currentPrice, 2);
        }

        $message = "🔔 <b>ALERT: {$alert->ticker} MENCAPAI TARGET!</b>\n\n"
            ."Harga {$directionText} {$targetFormatted} (target kamu)\n"
            ."Harga sekarang: {$currentFormatted}\n\n"
            ."/price_{$alert->ticker} — cek detail\n"
            .'/alerts — lihat semua alert';

        try {
            $telegram = new Api(config('stock_alert.telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id' => $user->chat_id,
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);

            NotificationLog::create([
                'user_id' => $user->id,
                'price_alert_id' => $alert->id,
                'ticker' => $alert->ticker,
                'message' => "Harga {$directionText} {$targetFormatted}. Current: {$currentFormatted}",
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Exception $e) {
            NotificationLog::create([
                'user_id' => $user->id,
                'price_alert_id' => $alert->id,
                'ticker' => $alert->ticker,
                'message' => "Harga {$directionText} {$targetFormatted}. Current: {$currentFormatted}",
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
