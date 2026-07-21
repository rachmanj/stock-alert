<?php

namespace App\Services;

use App\Models\PriceAlert;
use App\Models\PriceHistory;
use App\Models\TrackedStock;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class TelegramBotService
{
    public function __construct(
        private StockPriceService $stockPriceService,
    ) {}

    public function handle(User $user, string $text): string
    {
        try {
            $text = trim($text);

            if (! str_starts_with($text, '/')) {
                return $this->unknownCommand();
            }

            $parts = explode(' ', $text);
            $command = strtolower(explode('@', $parts[0])[0]);
            $args = array_slice($parts, 1);

            return match ($command) {
                '/start' => $this->handleStart($user),
                '/help' => $this->handleHelp(),
                '/track' => $this->handleTrack($user, $args),
                '/untrack' => $this->handleUntrack($user, $args),
                '/watchlist' => $this->handleWatchlist($user),
                '/alert' => $this->handleAlert($user, $args),
                '/alerts' => $this->handleAlerts($user),
                '/cancela', '/cancelalert' => $this->handleCancelAlert($user, $args),
                '/price' => $this->handlePrice($user, $args),
                '/stats' => $this->handleStats($user),
                default => $this->unknownCommand(),
            };
        } catch (\Exception $e) {
            Log::error('TelegramBotService error: '.$e->getMessage(), [
                'user_id' => $user->id,
                'text' => $text,
                'trace' => $e->getTraceAsString(),
            ]);

            return '❌ Maaf, terjadi kesalahan. Tim kami sudah diberitahu. Coba /help untuk bantuan.';
        }
    }

    public function sendToUser(User $user, string $text): void
    {
        $telegram = new Api(config('stock_alert.telegram.bot_token'));
        $telegram->sendMessage([
            'chat_id' => $user->chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    private function handleStart(User $user): string
    {
        $name = $user->first_name ?? 'Trader';
        $msg = "🚀 <b>Halo {$name}!</b> Stock Alert siap bantu pantau saham kamu.\n\n";
        $msg .= "📌 <b>Mulai cepat:</b>\n";
        $msg .= "/track BBCA.JK — pantau saham Bank Central Asia\n";
        $msg .= "/track TLKM.JK — pantau saham Telkom Indonesia\n";
        $msg .= "/track AAPL — pantau saham Apple (US)\n\n";
        $msg .= "💡 <b>Setelah track, set alert:</b>\n";
        $msg .= "/alert BBCA.JK 8000 bawah\n\n";
        $msg .= 'Ketik /help untuk lihat semua perintah.';

        return $msg;
    }

    private function handleHelp(): string
    {
        return "📖 <b>Perintah Stock Alert</b>\n\n"
            ."<b>Watchlist</b>\n"
            ."/track &lt;ticker&gt; — tambah saham ke watchlist\n"
            ."/untrack &lt;ticker&gt; — hapus dari watchlist\n"
            ."/watchlist — lihat saham yang dipantau\n"
            ."/price &lt;ticker&gt; — cek harga real-time\n\n"
            ."<b>Alert</b>\n"
            ."/alert &lt;ticker&gt; &lt;harga&gt; &lt;atas/bawah&gt; — set alert harga\n"
            ."/alerts — lihat alert aktif\n"
            ."/cancela &lt;id&gt; — batalkan alert\n\n"
            ."<b>Lainnya</b>\n"
            ."/stats — statistik akun kamu\n"
            .'/help — tampilkan bantuan ini';
    }

    private function handleTrack(User $user, array $args): string
    {
        if (empty($args[0])) {
            return '❌ Format: /track &lt;ticker&gt; (contoh: /track BBCA.JK)';
        }

        $ticker = $this->normalizeTicker($args[0]);

        if (! $this->isValidTicker($ticker)) {
            return '❌ Format ticker tidak valid. Contoh: BBCA.JK, TLKM.JK, AAPL';
        }

        $existing = $user->trackedStocks()->where('ticker', $ticker)->first();

        if ($existing?->active) {
            return 'Kamu sudah track ticker ini.';
        }

        $trackedCount = $user->trackedStocks()->where('active', true)->count();
        if (! $existing && $trackedCount >= config('stock_alert.limits.max_tickers_per_user')) {
            return '⚠️ Kamu sudah mencapai batas maksimum '.config('stock_alert.limits.max_tickers_per_user').' ticker. Hapus beberapa dengan /untrack <ticker> dulu.';
        }

        $priceData = $this->stockPriceService->fetchPrice($ticker);

        if (isset($priceData['error'])) {
            return "❌ {$priceData['error']}";
        }

        $name = $priceData['name'] ?? $ticker;

        if ($existing) {
            $existing->update(['active' => true, 'name' => $name]);
        } else {
            TrackedStock::create([
                'user_id' => $user->id,
                'ticker' => $ticker,
                'name' => $name,
                'active' => true,
            ]);
        }

        PriceHistory::create([
            'ticker' => $ticker,
            'price' => $priceData['price'],
            'change' => $priceData['change'],
            'change_percent' => $priceData['change_percent'],
            'recorded_at' => now(),
        ]);

        $formattedPrice = $this->formatPrice($ticker, $priceData['price']);

        return "✅ <b>{$ticker}</b> ({$name}) ditambahkan. Harga: {$formattedPrice} | /watchlist";
    }

    private function handleUntrack(User $user, array $args): string
    {
        if (empty($args[0])) {
            return '❌ Format: /untrack &lt;ticker&gt; (contoh: /untrack BBCA.JK)';
        }

        $ticker = $this->normalizeTicker($args[0]);
        $stock = $user->trackedStocks()->where('ticker', $ticker)->where('active', true)->first();

        if (! $stock) {
            return 'Kamu belum track ticker ini.';
        }

        $stock->update(['active' => false]);

        return "❌ <b>{$ticker}</b> dihapus dari watchlist.";
    }

    private function handleWatchlist(User $user): string
    {
        $stocks = $user->trackedStocks()->where('active', true)->orderBy('ticker')->get();

        if ($stocks->isEmpty()) {
            return 'Kamu belum track saham apapun. Mulai dengan /track BBCA.JK';
        }

        $lines = ["📋 <b>Watchlist kamu</b>\n"];
        $lines[] = '<pre>Ticker    | Harga       | Change';
        $lines[] = '----------|-------------|--------';

        foreach ($stocks as $stock) {
            $latest = PriceHistory::where('ticker', $stock->ticker)
                ->orderByDesc('recorded_at')
                ->first();

            $price = $latest ? $this->formatPrice($stock->ticker, (float) $latest->price) : '-';
            $change = $latest ? $this->formatChangePercent((float) $latest->change_percent) : '-';

            $lines[] = sprintf('%-9s | %-11s | %s', $stock->ticker, $price, $change);
        }

        $lines[] = '</pre>';

        return implode("\n", $lines);
    }

    private function handleAlert(User $user, array $args): string
    {
        if (count($args) < 3) {
            return '❌ Format: /alert &lt;ticker&gt; &lt;harga&gt; &lt;atas/bawah&gt;';
        }

        $ticker = $this->normalizeTicker($args[0]);
        $targetPrice = $args[1];
        $direction = strtolower($args[2]);

        if (! $this->isValidTicker($ticker)) {
            return '❌ Format ticker tidak valid. Contoh: BBCA.JK, TLKM.JK, AAPL';
        }

        if (! is_numeric($targetPrice) || (float) $targetPrice <= 0) {
            return '❌ Harga target harus angka positif.';
        }

        if (! in_array($direction, ['atas', 'bawah'], true)) {
            return '❌ Direction harus <b>atas</b> atau <b>bawah</b>.';
        }

        $stock = $user->trackedStocks()->where('ticker', $ticker)->where('active', true)->first();

        if (! $stock) {
            return "❌ Kamu belum track ticker ini. /track {$ticker} dulu.";
        }

        $alertCount = $user->priceAlerts()->where('is_triggered', false)->count();
        if ($alertCount >= config('stock_alert.limits.max_alerts_per_user')) {
            return '⚠️ Kamu sudah mencapai batas maksimum '.config('stock_alert.limits.max_alerts_per_user').' alert aktif. Cancel beberapa dengan /cancela <id> dulu.';
        }

        PriceAlert::create([
            'user_id' => $user->id,
            'tracked_stock_id' => $stock->id,
            'ticker' => $ticker,
            'target_price' => $targetPrice,
            'direction' => $direction,
            'is_triggered' => false,
        ]);

        $formattedPrice = $this->formatPrice($ticker, (float) $targetPrice);

        return "🔔 Alert diset! <b>{$ticker}</b> {$direction} {$formattedPrice}";
    }

    private function handleAlerts(User $user): string
    {
        $alerts = $user->priceAlerts()
            ->where('is_triggered', false)
            ->orderBy('id')
            ->get();

        if ($alerts->isEmpty()) {
            return 'Kamu belum punya alert aktif. /alert &lt;ticker&gt; &lt;harga&gt; &lt;atas/bawah&gt;';
        }

        $lines = ["🔔 <b>Alert aktif</b>\n"];
        $lines[] = '<pre>ID  | Ticker    | Target      | Dir';
        $lines[] = '----|----------|-------------|------';

        foreach ($alerts as $alert) {
            $price = $this->formatPrice($alert->ticker, (float) $alert->target_price);
            $lines[] = sprintf(
                '%-3d | %-8s | %-11s | %s',
                $alert->id,
                $alert->ticker,
                $price,
                $alert->direction
            );
        }

        $lines[] = '</pre>';
        $lines[] = "\nCancel: /cancela &lt;id&gt;";

        return implode("\n", $lines);
    }

    private function handleCancelAlert(User $user, array $args): string
    {
        if (empty($args[0]) || ! is_numeric($args[0])) {
            return '❌ Format: /cancela &lt;id&gt; (lihat ID di /alerts)';
        }

        $alert = $user->priceAlerts()->where('id', (int) $args[0])->first();

        if (! $alert) {
            return '❌ Alert tidak ditemukan atau bukan milik kamu.';
        }

        $alertId = $alert->id;
        $alert->delete();

        return "Alert #{$alertId} dicancel.";
    }

    private function handlePrice(User $user, array $args): string
    {
        if (empty($args[0])) {
            return '❌ Format: /price &lt;ticker&gt; (contoh: /price BBCA.JK)';
        }

        $ticker = $this->normalizeTicker($args[0]);

        if (! $this->isValidTicker($ticker)) {
            return '❌ Format ticker tidak valid. Contoh: BBCA.JK, TLKM.JK, AAPL';
        }

        $priceData = $this->stockPriceService->fetchPrice($ticker);

        if (isset($priceData['error'])) {
            return "❌ {$priceData['error']}";
        }

        $formattedPrice = $this->formatPrice($ticker, $priceData['price']);
        $change = $this->formatChangePercent($priceData['change_percent']);

        return "<b>{$ticker}</b>: {$formattedPrice} (change: {$change})";
    }

    private function handleStats(User $user): string
    {
        $tracked = $user->trackedStocks()->where('active', true)->count();
        $alerts = $user->priceAlerts()->where('is_triggered', false)->count();
        $notifications = $user->notificationLogs()->where('status', 'sent')->count();

        return "📊 <b>Statistik kamu:</b> {$tracked} track, {$alerts} alert, {$notifications} notif";
    }

    private function unknownCommand(): string
    {
        return '❓ Perintah tidak dikenali. Ketik /help untuk daftar perintah.';
    }

    private function normalizeTicker(string $ticker): string
    {
        return strtoupper(trim($ticker));
    }

    private function isValidTicker(string $ticker): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{1,10}(\.[A-Z0-9]{1,3})?$/', $ticker);
    }

    private function formatPrice(string $ticker, float $price): string
    {
        if (str_ends_with($ticker, '.JK')) {
            return 'Rp '.number_format($price, 0, ',', '.');
        }

        return '$'.number_format($price, 2, '.', ',');
    }

    private function formatChangePercent(float $percent): string
    {
        $sign = $percent >= 0 ? '+' : '';

        return $sign.number_format($percent, 2).'%';
    }
}
