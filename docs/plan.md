# Stock Alert — Rencana Implementasi (MVP — Multi-User SaaS)

> **Dibuat:** 2026-07-21 · **Diupdate:** 2026-07-21 (restrukturisasi SaaS)
> **Untuk:** Iwan — developer Laravel + React + Inertia
> **Target user:** Multi-user — trader ritel pantau harga saham + terima alert via Telegram
> **Server target:** Ubuntu 24.04 (dea-geekom)
> **Prinsip:** MVP solid, arsitektur siap multi-user sejak awal, monetisasi nanti.

---

## Daftar Isi

1. [Konsep & Arsitektur](#1-konsep--arsitektur)
   - 1.1 Gambaran Sistem SaaS
   - 1.2 Flow Utama (Fetch → Compare → Alert)
   - 1.3 Arsitektur Multi-User Deep Dive
   - 1.4 Scheduler & Queue Design
2. [Tech Stack Spesifik](#2-tech-stack-spesifik)
3. [Database Schema](#3-database-schema)
4. [UX / Flow Description](#4-ux--flow-description)
   - 4.1 Telegram Bot Commands
   - 4.2 Onboarding Flow
   - 4.3 Notifikasi Alert
   - 4.4 Web Dashboard (Iterasi 2)
   - 4.5 Admin Dashboard (Iterasi 2)
5. [Task Breakdown](#5-task-breakdown)
6. [Deployment Plan](#6-deployment-plan)
7. [Multi-User Testing Checklist](#7-multi-user-testing-checklist)
8. [Estimasi Waktu](#8-estimasi-waktu)

---

## 1. Konsep & Arsitektur

### 1.1 Gambaran Sistem SaaS

Stock Alert adalah aplikasi **multi-user SaaS** berbasis Laravel + Telegram Bot. Setiap user berinteraksi secara mandiri melalui Telegram bot untuk set alert dan terima notifikasi. Web dashboard (Iterasi 2) melayani manajemen akun dan overview portfolio. Aplikasi didesain dari awal dengan **data isolation per user** — Iwan bisa onboard 1000 user tanpa satu pun melihat data user lain.

```
┌──────────────────────────────────────────────────────────────────┐
│                    MULTIPLE TELEGRAM USERS                        │
│                                                                  │
│  User A: /track BBCA.JK, /alert BBCA.JK 8000 bawah               │
│  User B: /track TLKM.JK, /alert TLKM.JK 4000 atas                │
│  User C: /track AAPL,   /alert AAPL 300 bawah                    │
│  ... up to N users ...                                           │
└───────────────────────┬──────────────────────────────────────────┘
                        │ (Telegram Bot API — webhook)
                        ▼
┌──────────────────────────────────────────────────────────────────┐
│              Stock Alert — Laravel App (SaaS Core)                │
│                                                                  │
│  ┌────────────────┐  ┌──────────────────┐  ┌──────────────────┐  │
│  │ Telegram       │  │ Rate Limiter     │  │ Web Dashboard    │  │
│  │ Webhook        │  │ (throttle:30,1)  │  │ (React+Inertia)  │  │
│  │ Controller     │  │ per Telegram ID  │  │ Iterasi 2        │  │
│  └───────┬────────┘  └────────┬─────────┘  └────────┬─────────┘  │
│          │                    │                      │            │
│          └────────────────────┼──────────────────────┘            │
│                               ▼                                   │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │                    Price Engine                              │  │
│  │                                                             │  │
│  │  stocks:fetch-prices (Artisan command, cron every 5 min)    │  │
│  │     │                                                       │  │
│  │     ├─ 1. SELECT DISTINCT ticker (dedup across all users)   │  │
│  │     ├─ 2. Batch fetch harga via Yahoo Finance v8            │  │
│  │     ├─ 3. Cache harga (60s TTL, hindari re-fetch)          │  │
│  │     ├─ 4. Simpan price_history                              │  │
│  │     └─ 5. AlertEvaluator: compare + dispatch notifications  │  │
│  └────────────────────────────────────────────────────────────┘  │
│                               │                                   │
│  ┌────────────────────────────┴──────────────────────────────┐  │
│  │  MySQL 8                                                   │  │
│  │  users │ tracked_stocks │ price_alerts │ price_history     │  │
│  │  notification_logs │ rate_limit_logs │ admin_metrics       │  │
│  │  (semua tabel punya user_id FK — data isolation built-in)  │  │
│  └───────────────────────────────────────────────────────────┘  │
└───────────────────────┬──────────────────────────────────────────┘
                        │ (HTTPS)
                        ▼
            ┌──────────────────────────┐
            │  Yahoo Finance v8 API    │
            │  (gratis, support .JK)   │
            └──────────────────────────┘
```

### 1.2 Flow Utama

#### A. Fetch Harga Saham — dengan Deduplication (Setiap 5 menit via cron)

Ini adalah **core loop** aplikasi. Kunci skalabilitas: **deduplicate ticker dulu, baru fetch**.

```
cron: php artisan schedule:run (every minute)
    │
    └─▶ stocks:fetch-prices (everyFiveMinutes, withoutOverlapping)
           │
           ▼
    1. SELECT DISTINCT ticker FROM tracked_stocks WHERE active = 1
       → ['BBCA.JK', 'TLKM.JK', 'AAPL', 'GOTO.JK']  (4 ticker unique)
       → BUKAN 4 ticker × N user

           │
           ▼
    2. Cache check: untuk setiap ticker, cek apakah harga sudah
       di-fetch dalam 60 detik terakhir (dari price_history).
       Ticker yang masih fresh → skip API call.
       → Misal: BBCA.JK sudah di-fetch 30 detik lalu → skip
       → Jadi hanya 3 API call, bukan 4

           │
           ▼
    3. Fetch harga per ticker via Yahoo Finance v8 API
       (sequential, rate-limited: delay 200ms antar call)

           │
           ▼
    4. Simpan ke price_history (hanya kalau harga berubah)

           │
           ▼
    5. Untuk setiap ticker yang HARGA BERUBAH:
       SELECT * FROM price_alerts
       WHERE ticker = 'BBCA.JK' AND is_triggered = 0
       -- Query ini mengambil alert dari SEMUA user untuk ticker tsb

           │
           ▼
    6. AlertEvaluator: bandingkan currentPrice vs previousPrice
       Pakai "cross-over" logic (section 3.2 detail)
       ┌─ Triggered? ─▶ NotificationService::send()
       │                 ┌─ Format pesan Telegram
       │                 ├─ Kirim via Bot API (per user chat_id)
       │                 ├─ INSERT notification_log
       │                 └─ UPDATE price_alerts SET is_triggered = 1
       └─ Not triggered → skip

```

**Kenapa flow ini scalable:**
- Jumlah API call = jumlah unique ticker (bukan jumlah user × ticker)
- 1000 user tracking 50 ticker berbeda → hanya 50 API call per 5 menit
- Cache mencegah re-fetch ticker yang baru saja di-fetch
- Evaluasi alert per ticker = 1 query `WHERE ticker = X`, cepat dengan index

**Bottleneck yang mungkin dan mitigasinya:**

| Bottleneck | Dampak | Mitigasi |
|---|---|---|
| 1000 unique ticker = 1000 API call | Rate limit Yahoo Finance, fetch lambat | Batch dengan delay, queue job, atau multi-stage fetch (prioritas: ticker dengan alert aktif dulu, watchlist-only fetch less frequent) |
| Banyak alert untuk 1 ticker populer (misal BBCA.JK di-track 500 user) | Loop evaluasi + kirim Telegram 500 notifikasi | Dispatch notification ke queue job, bukan inline |
| price_history membesar (1 row per ticker per 5 menit × 500 ticker × 12/hari) | ~144K rows/hari. Dalam setahun 52M rows | Partition by month, archive > 90 hari |
| Webhook Telegram lambat (N user kirim command bersamaan) | Response lambat ke user | Laravel queue untuk heavy processing, throttle middleware, pastikan webhook respond < 1 detik (acknowledge dulu, process di queue) |

#### B. User Interaction Flow (Telegram Bot)

```
User baru: /start
    │
    ▼
Bot: "🚀 Halo! Stock Alert siap bantu pantau saham kamu.
      Coba mulai cepat: /track BBCA.JK"
    [DB: firstOrCreate user based on telegram_id]

User: /track BBCA.JK
    │
    ▼
Bot: "✅ BBCA.JK (Bank Central Asia Tbk.) — Rp 8,250
      Kamu track 1 saham. /watchlist untuk lihat semua."

User: /alert BBCA.JK 8000 bawah
    │
    ▼
Bot: "🔔 Alert diset! Kamu akan dapat notifikasi kalau
      BBCA.JK turun ke ≤ Rp 8,000.
      Harga sekarang: Rp 8,250"

... 30 menit kemudian, cron job fetch harga ...

BBCA.JK sekarang Rp 7,980 (turun dari Rp 8,100)
    │
    ▼
Cross-over terdeteksi: previousPrice (8100) > target (8000) >= currentPrice (7980)
    │
    ▼
Bot kirim: "🔔 ALERT: BBCA.JK TURUN KE Rp 7,980
           Target kamu: Rp 8,000
           Perubahan: -1.5% hari ini
           /detail_BBCA.JK | /alerts"
```

### 1.3 Arsitektur Multi-User Deep Dive

#### 1.3.1 Deduplication & Batch Fetch — Code Structure

File: `app/Services/StockPriceService.php`

```php
<?php

namespace App\Services;

use App\Models\PriceHistory;
use App\Models\TrackedStock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class StockPriceService
{
    /**
     * Fetch harga untuk semua unique ticker yang aktif.
     * Dedup across all users + cache check.
     *
     * @return array<string, array>  ['BBCA.JK' => ['price' => 8250, ...], ...]
     */
    public function fetchAllActiveTickers(): array
    {
        // STEP 1: Ambil semua unique ticker dari SEMUA user
        $tickers = TrackedStock::query()
            ->where('active', true)
            ->distinct()
            ->pluck('ticker')
            ->toArray();

        // STEP 2: Filter — skip ticker yang baru saja di-fetch (< 60 detik)
        $recentlyFetched = PriceHistory::query()
            ->whereIn('ticker', $tickers)
            ->where('recorded_at', '>=', now()->subSeconds(60))
            ->pluck('ticker')
            ->toArray();

        $tickersToFetch = array_diff($tickers, $recentlyFetched);

        if (empty($tickersToFetch)) {
            return [];
        }

        // STEP 3: Fetch harga — sequential dengan delay anti rate-limit
        $prices = [];
        foreach ($tickersToFetch as $ticker) {
            $price = $this->fetchPrice($ticker);
            if ($price !== null) {
                $prices[$ticker] = $price;
            }
            // Delay 200ms antar API call untuk hindari rate limit
            usleep(200_000);
        }

        return $prices;
    }

    /**
     * Fetch harga satu ticker dari Yahoo Finance v8 API.
     */
    public function fetchPrice(string $ticker): ?array
    {
        $response = Http::timeout(10)
            ->retry(2, 500)
            ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$ticker}", [
                'interval' => '1d',
                'range'    => '1d',
            ]);

        if (!$response->successful()) {
            return null;
        }

        $result = $response->json('chart.result.0');
        if (!$result) {
            return null;
        }

        $meta = $result['meta'];

        return [
            'ticker'         => $ticker,
            'price'          => $meta['regularMarketPrice'] ?? null,
            'previous_close' => $meta['chartPreviousClose'] ?? $meta['previousClose'] ?? null,
            'change'         => ($meta['regularMarketPrice'] ?? 0) - ($meta['chartPreviousClose'] ?? 0),
            'change_percent' => $meta['regularMarketPrice']
                ? (($meta['regularMarketPrice'] - ($meta['chartPreviousClose'] ?? 0)) / ($meta['chartPreviousClose'] ?: 1)) * 100
                : null,
        ];
    }
}
```

File: `app/Console/Commands/StocksFetchPrices.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\PriceHistory;
use App\Services\AlertEvaluatorService;
use App\Services\StockPriceService;
use Illuminate\Console\Command;

class StocksFetchPrices extends Command
{
    protected $signature = 'stocks:fetch-prices';
    protected $description = 'Fetch harga semua unique ticker, simpan, evaluasi alert';

    public function handle(StockPriceService $stockPrice, AlertEvaluatorService $evaluator): int
    {
        // 1. Fetch semua harga (dedup + cache-aware)
        $prices = $stockPrice->fetchAllActiveTickers();

        if (empty($prices)) {
            $this->info('No tickers to fetch or all recently fetched.');
            return self::SUCCESS;
        }

        $this->info('Fetched ' . count($prices) . ' tickers.');

        // 2. Simpan ke price_history dan evaluasi alert
        foreach ($prices as $ticker => $data) {
            // Ambil harga sebelumnya untuk cross-over logic
            $prevRecord = PriceHistory::query()
                ->where('ticker', $ticker)
                ->latest('recorded_at')
                ->first();

            $previousPrice = $prevRecord?->price;

            // Simpan harga baru (hanya kalau berubah)
            if ($previousPrice === null || $previousPrice != $data['price']) {
                PriceHistory::create([
                    'ticker'         => $ticker,
                    'price'          => $data['price'],
                    'change'         => $data['change'],
                    'change_percent' => round($data['change_percent'], 2),
                    'recorded_at'    => now(),
                ]);
            }

            // 3. Evaluasi alert (jika harga berubah)
            if ($previousPrice !== null && $previousPrice != $data['price']) {
                $evaluator->evaluate($ticker, $data['price'], $previousPrice);
            }
        }

        return self::SUCCESS;
    }
}
```

#### 1.3.2 Data Isolation — Arsitektur Defense-in-Depth

Data isolation dijamin di **3 layer**:

| Layer | Mekanisme | File |
|-------|-----------|------|
| **Database** | `user_id` FK + unique constraint `['user_id', 'ticker']` di `tracked_stocks`. Foreign key `CASCADE ON DELETE`. | Migrations |
| **Model / Query** | Semua query di-scope ke user saat ini: `TrackedStock::where('user_id', auth()->id())`. Policy/Gate untuk otorisasi. | Models, Policies |
| **Application** | Middleware extract `telegram_id` dari webhook payload, resolve ke `User` model, inject ke request context. Controller tidak pernah query tanpa `user_id` filter. | Middleware, Controller |

**Prinsip kunci:** Controller TIDAK PERNAH menerima `user_id` dari input. User identity diambil dari `telegram_id` yang ada di webhook payload (otoritatif dari Telegram, tidak bisa di-spoof).

```php
// app/Http/Middleware/ResolveTelegramUser.php
<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class ResolveTelegramUser
{
    public function handle(Request $request, Closure $next)
    {
        $chatId = $request->input('message.chat.id')
               ?? $request->input('callback_query.message.chat.id');

        if (!$chatId) {
            return response()->json(['ok' => true]); // Ack, ignore non-message
        }

        $user = User::firstOrCreate(
            ['telegram_id' => $request->input('message.from.id')],
            [
                'chat_id'            => $chatId,
                'telegram_username'  => $request->input('message.from.username'),
                'first_name'         => $request->input('message.from.first_name'),
            ]
        );

        // Inject ke request context — semua controller pakai ini
        $request->merge(['auth_user' => $user]);

        return $next($request);
    }
}
```

#### 1.3.3 Rate Limiting & Fair Usage

**Throttle per Telegram user** — menggunakan Laravel built-in throttle middleware.

File: `routes/web.php` (atau `routes/telegram.php`)

```php
<?php

use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', [TelegramController::class, 'handle'])
    ->middleware('throttle:telegram'); // Custom throttle
```

File: `app/Http/Kernel.php` (atau `bootstrap/app.php` untuk Laravel 11)

```php
// bootstrap/app.php — Laravel 11 style
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::for('telegram', function (Request $request) {
    // Rate limit by Telegram user ID (from webhook payload)
    $userId = $request->input('message.from.id')
           ?? $request->input('callback_query.from.id')
           ?? $request->ip(); // fallback

    return Limit::perMinute(30)->by('telegram:' . $userId);
});
```

**Quota / fair usage (config-based, siap untuk monetisasi nanti):**

File: `config/stock_alert.php`

```php
<?php

return [
    // Per-user limits — siap untuk nanti jadi tiered (free/pro)
    'limits' => [
        'max_tickers_per_user'  => env('MAX_TICKERS_PER_USER', 50),
        'max_alerts_per_user'   => env('MAX_ALERTS_PER_USER', 30),
        'telegram_rate_per_min' => env('TELEGRAM_RATE_PER_MIN', 30),
    ],

    // Fetch settings
    'fetch' => [
        'cache_ttl_seconds'    => env('FETCH_CACHE_TTL', 60),
        'delay_between_calls'  => env('FETCH_DELAY_MS', 200),
        'yahoo_timeout'        => env('YAHOO_TIMEOUT', 10),
    ],

    // Popular tickers for onboarding suggestions
    'popular_tickers' => [
        'IDX' => ['BBCA.JK', 'TLKM.JK', 'ASII.JK', 'BMRI.JK', 'GOTO.JK', 'ADRO.JK'],
        'US'  => ['AAPL', 'TSLA', 'NVDA', 'MSFT', 'GOOGL'],
    ],
];
```

**Pengecekan limit di controller:**

```php
// Di TrackCommand handler
if ($user->trackedStocks()->where('active', true)->count() >= config('stock_alert.limits.max_tickers_per_user')) {
    TelegramBotService::sendMessage(
        $user->chat_id,
        "⚠️ Kamu sudah mencapai batas maksimum " . config('stock_alert.limits.max_tickers_per_user') . " ticker."
    );
    return;
}
```

#### 1.3.4 Alert Evaluator — Cross-Over Logic

File: `app/Services/AlertEvaluatorService.php`

```php
<?php

namespace App\Services;

use App\Models\PriceAlert;
use Illuminate\Support\Facades\Log;

class AlertEvaluatorService
{
    /**
     * Evaluasi semua alert untuk ticker tertentu.
     * Cross-over logic mencegah spam: alert hanya trigger saat
     * harga MENEMBUS threshold (bukan saat sudah di bawah/atas).
     */
    public function evaluate(string $ticker, float $currentPrice, float $previousPrice): void
    {
        $alerts = PriceAlert::query()
            ->where('ticker', $ticker)
            ->where('is_triggered', false)
            ->with('user') // eager load user buat ambil chat_id
            ->get();

        foreach ($alerts as $alert) {
            $triggered = false;

            if ($alert->direction === 'bawah') {
                // Trigger kalau: sebelumnya > target DAN sekarang ≤ target
                // (baru saja turun menembus target dari atas)
                if ($previousPrice > $alert->target_price
                    && $currentPrice <= $alert->target_price) {
                    $triggered = true;
                }
            } elseif ($alert->direction === 'atas') {
                // Trigger kalau: sebelumnya < target DAN sekarang ≥ target
                // (baru saja naik menembus target dari bawah)
                if ($previousPrice < $alert->target_price
                    && $currentPrice >= $alert->target_price) {
                    $triggered = true;
                }
            }

            if ($triggered) {
                $this->fireAlert($alert, $currentPrice);
            }
        }
    }

    private function fireAlert(PriceAlert $alert, float $currentPrice): void
    {
        $ticker = $alert->ticker;
        $direction = $alert->direction === 'atas' ? 'NAIK' : 'TURUN';

        $message = "🔔 ALERT: {$ticker} {$direction} KE Rp " . number_format($currentPrice, 0, ',', '.') . "\n\n"
                 . "Target kamu: Rp " . number_format($alert->target_price, 0, ',', '.') . "\n"
                 . "Harga sekarang: Rp " . number_format($currentPrice, 0, ',', '.') . "\n\n"
                 . "/detail_{$ticker}  |  /alerts";

        // Kirim notifikasi via Telegram
        $sent = app(NotificationService::class)->sendTelegram(
            $alert->user->chat_id,
            $message
        );

        // Update status alert
        $alert->update([
            'is_triggered' => true,
            'triggered_at' => now(),
        ]);

        // Log
        \App\Models\NotificationLog::create([
            'user_id'        => $alert->user_id,
            'price_alert_id' => $alert->id,
            'ticker'         => $alert->ticker,
            'message'        => $message,
            'status'         => $sent ? 'sent' : 'failed',
            'sent_at'        => $sent ? now() : null,
        ]);
    }
}
```

**Edge case penting:** Setelah alert trigger, `is_triggered = true`. User harus **re-enable manual** (set alert lagi) untuk mengaktifkan ulang. Ini mencegah "flapping" — misal harga naik-turun di sekitar threshold yang akan memicu spam alert.

---

### 1.4 Scheduler & Queue Design

#### 1.4.1 MVP Phase (≤ 50 unique ticker, ≤ 100 user)

**Synchronous dalam scheduler — tidak butuh queue:**

```
cron (* * * * *) → Laravel scheduler → stocks:fetch-prices (synchronous)
```

File: `routes/console.php`

```php
<?php

use Illuminate\Support\Facades\Schedule;

// Fetch harga setiap 5 menit (market hours only untuk IDX)
Schedule::command('stocks:fetch-prices')
    ->everyFiveMinutes()
    ->weekdays()
    ->between('9:00', '16:00')
    ->withoutOverlapping(10); // max 10 menit running time

// Fetch 15 menit untuk US stocks (overlap dengan market hours yang berbeda)
// Untuk MVP: fetch terus aja, Yahoo Finance tetep return data di luar market hours
Schedule::command('stocks:fetch-prices')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);
```

Server cron entry (satu saja):

```cron
* * * * * cd /home/deahermes/stock-alert && php artisan schedule:run >> /dev/null 2>&1
```

#### 1.4.2 Scale Phase (50+ unique ticker atau 100+ user)

Kalau fetch mulai lambat (> 30 detik), pindah ke **queue**:

**Config queue:** Database driver cukup untuk skala ini.

```env
QUEUE_CONNECTION=database
```

**Queue worker via Supervisor:**

```ini
; /etc/supervisor/conf.d/stock-alert-worker.conf
[program:stock-alert-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/deahermes/stock-alert/artisan queue:work --sleep=3 --tries=3 --max-time=300
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deahermes
numprocs=2
redirect_stderr=true
stdout_logfile=/home/deahermes/stock-alert/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start stock-alert-worker:*
```

**Dispatch fetch job ke queue (bukan synchronous):**

File: `app/Jobs/FetchTickerPrice.php`

```php
<?php

namespace App\Jobs;

use App\Models\PriceHistory;
use App\Services\AlertEvaluatorService;
use App\Services\StockPriceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchTickerPrice implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $ticker) {}

    public function handle(StockPriceService $stockPrice, AlertEvaluatorService $evaluator): void
    {
        $data = $stockPrice->fetchPrice($this->ticker);
        if (!$data) return;

        $prevRecord = PriceHistory::where('ticker', $this->ticker)
            ->latest('recorded_at')->first();
        $previousPrice = $prevRecord?->price;

        if ($previousPrice === null || $previousPrice != $data['price']) {
            PriceHistory::create([...]);
        }

        if ($previousPrice !== null && $previousPrice != $data['price']) {
            $evaluator->evaluate($this->ticker, $data['price'], $previousPrice);
        }
    }
}
```

**Scheduler dispatch ke queue:**

```php
// routes/console.php — scale version
Schedule::call(function () {
    $tickers = \App\Models\TrackedStock::where('active', true)
        ->distinct()->pluck('ticker');

    foreach ($tickers as $ticker) {
        \App\Jobs\FetchTickerPrice::dispatch($ticker);
    }
})->everyFiveMinutes();
```

#### 1.4.3 Horizon (Redis-based, untuk 500+ unique ticker)

Untuk skala besar, pakai Redis + Laravel Horizon untuk monitoring queue:

```bash
composer require laravel/horizon
php artisan horizon:install
```

```env
QUEUE_CONNECTION=redis
```

Supervisor untuk Horizon:

```ini
[program:stock-alert-horizon]
process_name=%(program_name)s
command=php /home/deahermes/stock-alert/artisan horizon
autostart=true
autorestart=true
user=deahermes
redirect_stderr=true
stdout_logfile=/home/deahermes/stock-alert/storage/logs/horizon.log
```

**Untuk MVP: skip Horizon.** Database queue + synchronous scheduler sudah cukup. Upgrade ke Redis/Horizon hanya ketika:
- Unique ticker > 50 → fetch butuh > 30 detik
- User > 100 → banyak command Telegram perlu background processing
- Butuh monitoring queue (failed jobs, throughput)

---

## 2. Tech Stack Spesifik

### 2.1 Backend

| Komponen | Rekomendasi | Alasan |
|----------|------------|--------|
| **Framework** | Laravel 11.x | User expert, built-in: routing, ORM, queue, scheduler, rate limiter, HTTP client |
| **PHP** | PHP 8.2+ | Minimum Laravel 11 |
| **Database** | MySQL 8.x | User familiar, performa cukup |
| **Queue** | Database (MVP) → Redis (scale) | Tanpa dependency eksternal untuk MVP |
| **Scheduler** | Laravel Task Scheduling | 1 cron entry, built-in overlap protection |
| **Cache** | File (MVP) → Redis (scale) | Cache query, rate limiter storage |

### 2.2 Stock Price API

| API | Kelebihan | Kekurangan | Rekomendasi |
|-----|----------|-----------|-------------|
| **Yahoo Finance v8** (unofficial) | Gratis, support `.JK` + US, data real-time-ish | Rate limit unpredictable, unofficial | ⭐ **Primary** |
| **Alpha Vantage** | Official, documented | 25 req/hari free tier | Fallback (opsional) |

**Rekomendasi implementasi:** Direct HTTP via Laravel `Http::get()` ke Yahoo Finance v8.

```
GET https://query1.finance.yahoo.com/v8/finance/chart/BBCA.JK?interval=1d&range=1d
```

Response JSON mengandung `chart.result[0].meta.regularMarketPrice`, `previousClose`, `chartPreviousClose`. Tanpa package tambahan, tanpa dependency Python.

### 2.3 Telegram Bot

| Library | Kelebihan |
|---------|----------|
| **irazasyed/telegram-bot-sdk** | Paling populer di Laravel, API lengkap, support Laravel 11 |
| **nutgram/laravel** | Modern, lebih ringan |

**Rekomendasi:** `irazasyed/telegram-bot-sdk:~3.10`

```bash
composer require irazasyed/telegram-bot-sdk
```

### 2.4 Frontend (Web Dashboard — Iterasi 2)

| Komponen | Rekomendasi |
|----------|------------|
| **Framework** | React 18 + Inertia.js |
| **CSS** | Tailwind CSS 3.x |
| **Chart** | Chart.js + react-chartjs-2 |
| **Build** | Vite (Laravel default) |
| **Auth** | Telegram OAuth / magic link via bot |

### 2.5 Development Tools

| Tool | Kegunaan |
|------|----------|
| **Laravel Pint** | Code style |
| **Pest PHP** (opsional) | Testing (simpler dari PHPUnit) |
| **Laravel Debugbar** (dev only) | Debug query, request |

---

## 3. Database Schema

### 3.1 ERD

```
┌──────────────┐       ┌──────────────────┐       ┌──────────────┐
│    users     │       │  tracked_stocks  │       │ price_alerts │
├──────────────┤       ├──────────────────┤       ├──────────────┤
│ id           │──┐    │ id               │    ┌──│ id           │
│ telegram_id  │  │    │ user_id (FK)     │◄───┘  │ user_id (FK) │
│ telegram_    │  │    │ ticker           │       │ tracked_stock │
│   username   │  │    │ name             │       │   _id (FK)    │
│ first_name   │  ├───▶│ active (bool)    │       │ ticker        │
│ chat_id      │  │    │ created_at       │       │ target_price  │
│ is_active    │  │    │ updated_at       │       │ direction     │
│ settings     │  │    └──────────────────┘       │   (atas/bawah)│
│   (JSON)     │  │                               │ is_triggered  │
│ created_at   │  │                               │ triggered_at  │
│ updated_at   │  │                               │ created_at    │
└──────────────┘  │                               │ updated_at    │
                  │                               └──────────────┘
                  │
                  │    ┌──────────────────┐       ┌──────────────┐
                  │    │  price_history   │       │notification  │
                  │    ├──────────────────┤       │   _logs      │
                  │    │ id               │       ├──────────────┤
                  │    │ ticker           │       │ id           │
                  └───▶│ price            │       │ user_id (FK) │
                       │ change           │       │ price_alert  │
                       │ change_percent   │       │   _id (FK)    │
                       │ recorded_at      │       │ ticker        │
                       │ created_at       │       │ message       │
                       └──────────────────┘       │ status        │
                                                  │ error_message │
                                                  │ sent_at       │
                                                  │ created_at    │
                                                  └──────────────┘
```

### 3.2 Migrations (Detail)

#### `users`

```php
// database/migrations/2026_07_21_000001_create_users_table.php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('telegram_id')->unique();        // ID dari Telegram (otoritatif)
    $table->string('telegram_username')->nullable();    // @username
    $table->string('first_name')->nullable();           // Nama depan
    $table->bigInteger('chat_id');                      // Chat ID untuk kirim notifikasi
    $table->boolean('is_active')->default(true);
    $table->json('settings')->nullable();               // {"timezone": "Asia/Jakarta", "language": "id"}
    $table->timestamp('last_interaction_at')->nullable(); // Untuk tracking user engagement
    $table->timestamps();

    $table->index('is_active');
});
```

#### `tracked_stocks`

```php
// database/migrations/2026_07_21_000002_create_tracked_stocks_table.php
Schema::create('tracked_stocks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('ticker', 20);                       // BBCA.JK, TLKM.JK, AAPL
    $table->string('name')->nullable();                 // "Bank Central Asia Tbk."
    $table->boolean('active')->default(true);
    $table->timestamps();

    // Data isolation: satu user ga bisa duplikasi ticker
    $table->unique(['user_id', 'ticker'], 'uq_user_ticker');

    // Index untuk dedup query: SELECT DISTINCT ticker WHERE active=1
    $table->index(['active', 'ticker']);
});
```

#### `price_alerts`

```php
// database/migrations/2026_07_21_000003_create_price_alerts_table.php
Schema::create('price_alerts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tracked_stock_id')->constrained()->cascadeOnDelete();
    $table->string('ticker', 20);                        // Denormalisasi biar query gampang
    $table->decimal('target_price', 15, 2);
    $table->enum('direction', ['atas', 'bawah']);
    $table->boolean('is_triggered')->default(false);
    $table->timestamp('triggered_at')->nullable();
    $table->timestamps();

    // Index untuk AlertEvaluator: WHERE ticker=X AND is_triggered=0
    $table->index(['ticker', 'is_triggered']);
    // User lihat alert sendiri
    $table->index(['user_id', 'is_triggered']);
});
```

#### `price_history`

```php
// database/migrations/2026_07_21_000004_create_price_history_table.php
Schema::create('price_history', function (Blueprint $table) {
    $table->id();
    $table->string('ticker', 20);
    $table->decimal('price', 15, 2);
    $table->decimal('change', 15, 2)->nullable();
    $table->decimal('change_percent', 8, 4)->nullable();
    $table->timestamp('recorded_at');                    // Waktu harga tercatat (bisa beda dgn created_at)
    $table->timestamps();

    // Composite index: cari harga terakhir & history per ticker
    $table->index(['ticker', 'recorded_at']);
});
```

#### `notification_logs`

```php
// database/migrations/2026_07_21_000005_create_notification_logs_table.php
Schema::create('notification_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('price_alert_id')->nullable()->constrained()->nullOnDelete();
    $table->string('ticker', 20);
    $table->text('message');
    $table->enum('status', ['sent', 'failed'])->default('sent');
    $table->text('error_message')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'created_at']);           // User query history
    $table->index('ticker');                            // Admin: notifikasi per ticker
});
```

### 3.3 Migration Order

1. `users`
2. `tracked_stocks`
3. `price_alerts`
4. `price_history`
5. `notification_logs`

---

## 4. UX / Flow Description

### 4.1 Telegram Bot Commands

Bot menggunakan **webhook mode** (lebih cepat dari long polling).

| Command | Deskripsi | Contoh Output |
|---------|----------|---------------|
| `/start` | Register auto + welcome + quick start | "🚀 Halo! Stock Alert siap bantu pantau saham kamu..." |
| `/help` | List semua commands | Tabel commands |
| `/track <ticker>` | Tambah ticker ke watchlist | "✅ BBCA.JK ditambahkan. Harga: Rp 8,250" |
| `/untrack <ticker>` | Hapus ticker dari watchlist | "❌ BBCA.JK dihapus." |
| `/watchlist` | List ticker + harga terkini + change% | Tabel: Ticker | Harga | Change% |
| `/alert <ticker> <harga> <atas/bawah>` | Set alert threshold | "🔔 Alert: BBCA.JK turun ke ≤ Rp 8,000" |
| `/alerts` | List semua alert aktif | Tabel: ID | Ticker | Target | Direction | Status |
| `/cancela <id>` | Cancel alert (validasi ownership) | "❌ Alert #3 (BBCA.JK @ 8000) dicancel." |
| `/price <ticker>` | Cek harga terkini real-time | "BBCA.JK: Rp 8,250 (+1.5%)" |
| `/stats` | Statistik alert user (lihat 4.1.1) | Total alert, triggered, notifikasi minggu ini |

#### 4.1.1 `/stats` Command — User Statistics

User bisa lihat statistik penggunaan mereka sendiri:

```
📊 Statistik kamu:

• Saham di-track: 12
• Alert aktif: 5
• Total alert triggered: 23
• Notifikasi minggu ini: 3
• Notifikasi bulan ini: 8
• User sejak: 15 Juli 2026
```

File: `app/Services/TelegramBotService.php` — handler `/stats`

```php
case '/stats':
    $stats = [
        'tracked'       => $user->trackedStocks()->where('active', true)->count(),
        'alerts_active' => $user->priceAlerts()->where('is_triggered', false)->count(),
        'alerts_total'  => $user->priceAlerts()->count(),
        'notif_week'    => $user->notificationLogs()
                            ->where('created_at', '>=', now()->startOfWeek())->count(),
        'notif_month'   => $user->notificationLogs()
                            ->where('created_at', '>=', now()->startOfMonth())->count(),
        'since'         => $user->created_at->translatedFormat('d F Y'),
    ];
    $this->sendMessage($user->chat_id, view('telegram.stats', $stats)->render());
    break;
```

### 4.2 Onboarding Flow (User Baru)

User auto-register saat `/start`. Welcome message didesain **engaging + actionable** — user langsung bisa coba dalam 30 detik.

```
User: /start

Bot:  ┌─────────────────────────────────────┐
      │  🚀 Halo, Iwan!                      │
      │  Stock Alert siap bantu kamu pantau  │
      │  harga saham & kasih notifikasi      │
      │  langsung ke Telegram.               │
      │                                      │
      │  ⚡ Mulai cepat:                      │
      │  /track BBCA.JK                      │
      │  /track TLKM.JK                      │
      │  /track ASII.JK                      │
      │                                      │
      │  📋 Atau lihat semua perintah:        │
      │  /help                               │
      │                                      │
      │  💡 Tips: Setelah track, langsung     │
      │  set alert:                           │
      │  /alert BBCA.JK 8000 bawah           │
      └─────────────────────────────────────┘
```

**Feature suggestion (opsional, simple):** Setelah `/track` pertama, bot tanya "Mau set alert untuk BBCA.JK? Ketik /alert BBCA.JK <harga> <atas/bawah>".

### 4.3 Notifikasi Alert (Format)

```
🔔 ALERT: BBCA.JK TURUN KE Rp 7,980

Target: Rp 8,000
Harga sekarang: Rp 7,980 (-1.5%)

/detail_BBCA.JK  |  /alerts
```

### 4.4 Web Dashboard — User (Iterasi 2, Penting untuk SaaS)

Web dashboard upgrade dari "nice-to-have" ke **"penting untuk SaaS"** karena memberikan user:

1. Visual overview portfolio (tidak praktis di Telegram)
2. Grafik harga historis per ticker
3. Manajemen alert yang lebih nyaman (CRUD via UI)
4. Export data

**Halaman User Dashboard:**

| Halaman | Konten |
|---------|--------|
| **Dashboard Home** | Ringkasan: total ticker, alert aktif, alert triggered, grafik mini portfolio |
| **Watchlist** | Tabel ticker + harga terkini + sparkline chart 7 hari |
| **Alerts** | CRUD alert: tambah, edit, delete, re-enable alert yang sudah triggered |
| **History** | Log notifikasi lengkap, filter by ticker & date range |
| **Ticker Detail** | Halaman per ticker: chart harga historis (line chart 30 hari), statistik, semua alert terkait |

**Auth web dashboard:** User login via **Telegram Login Widget** (official) atau **magic link** (bot kirim OTP). Tidak perlu password.

**Stack:** React 18 + Inertia.js + Tailwind CSS 3. Chart pakai Chart.js + react-chartjs-2.

**Rekomendasi:** Kerjakan web dashboard di **Iterasi 2**, setelah bot Telegram stabil dan ada user aktif. Tidak perlu menunda terlalu lama — dashboard adalah retention tool.

### 4.5 Admin Dashboard — Iwan (Iterasi 2)

Admin dashboard terpisah (atau section khusus dalam dashboard yang sama), untuk Iwan memonitor aplikasi.

**Halaman Admin Dashboard (`/admin`):**

| Halaman | Konten |
|---------|--------|
| **Overview** | Total user, active user (7 hari terakhir), total ticker tracked, total alert, notifikasi terkirim hari ini |
| **User List** | Tabel semua user: Telegram username, first_name, joined date, jumlah ticker, jumlah alert, last active |
| **Popular Tickers** | Top 20 ticker yang paling banyak di-track, dengan jumlah user per ticker |
| **Notification Stats** | Total notifikasi per hari (chart bar 30 hari), success rate, failed notifications |
| **System Health** | Scheduler status, last fetch time, jumlah unique ticker, API call count, average fetch duration |

**Query untuk admin dashboard (contoh):**

```php
// Popular tickers
TrackedStock::selectRaw('ticker, COUNT(DISTINCT user_id) as user_count')
    ->where('active', true)
    ->groupBy('ticker')
    ->orderByDesc('user_count')
    ->limit(20)
    ->get();

// Active users (interacted in last 7 days)
User::where('last_interaction_at', '>=', now()->subDays(7))->count();

// Notification stats 30 hari
NotificationLog::selectRaw('DATE(created_at) as date, COUNT(*) as count')
    ->where('created_at', '>=', now()->subDays(30))
    ->groupBy('date')
    ->orderBy('date')
    ->get();
```

**Auth admin:** Simple — bisa pakai Laravel basic auth atau tambahkan kolom `is_admin` di `users`. Untuk MVP, admin dashboard bisa jadi halaman yang hanya bisa diakses dari localhost (atau IP tertentu) tanpa auth — Iwan akses langsung dari server.

### 4.6 Fitur Masa Depan (Post-MVP / Monetisasi)

- Alert berbasis persentase (`/alert BBCA.JK -5%`)
- Daily summary: portfolio + rekap alert tiap pagi via Telegram
- Multiple alert condition (AND/OR: harga ≥ X DAN volume ≥ Y)
- Screener: "cari saham IDX yang turun > 3% hari ini"
- Export data ke CSV/Google Sheets
- Integrasi webhook (kirim alert ke sistem lain)
- Tiered pricing (free: 5 ticker, pro: unlimited)
- AI/sentiment analysis (overkill, skip untuk saat ini)

---

## 5. Task Breakdown

### Fase 0: Setup Project (1 jam)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 0.1 | Create Laravel project | `composer create-project laravel/laravel stock-alert` | Project skeleton |
| 0.2 | Setup MySQL database | Buat DB `stock_alert`, user `stock_alert`, config `.env` | `.env` |
| 0.3 | Install Telegram Bot SDK | `composer require irazasyed/telegram-bot-sdk` | `composer.json` |
| 0.4 | Create Telegram bot via @BotFather | Dapatkan `BOT_TOKEN`, set commands list | Token + commands |
| 0.5 | Init git & first commit | `.gitignore` (laravel default + .env), initial commit | Git repo |

### Fase 1: Database & Models (30 menit)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 1.1 | Migration: `users` | Schema lengkap (section 3.2), termasuk `last_interaction_at`, `settings` JSON | `database/migrations/..._create_users_table.php` |
| 1.2 | Migration: `tracked_stocks` | Unique constraint `['user_id', 'ticker']`, index `['active', 'ticker']` | `database/migrations/..._create_tracked_stocks_table.php` |
| 1.3 | Migration: `price_alerts` | Index `['ticker', 'is_triggered']` + `['user_id', 'is_triggered']` | `database/migrations/..._create_price_alerts_table.php` |
| 1.4 | Migration: `price_history` | Composite index `['ticker', 'recorded_at']` | `database/migrations/..._create_price_history_table.php` |
| 1.5 | Migration: `notification_logs` | Index `['user_id', 'created_at']` + `['ticker']` | `database/migrations/..._create_notification_logs_table.php` |
| 1.6 | Models + Relationships | `User`, `TrackedStock`, `PriceAlert`, `PriceHistory`, `NotificationLog` dengan Eloquent relationships | `app/Models/*.php` |
| 1.7 | Run migration | `php artisan migrate` | DB tables |

### Fase 2: Config & Foundation (30 menit)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 2.1 | Config `stock_alert.php` | Rate limits, max ticker/alert, popular tickers, fetch settings | `config/stock_alert.php` |
| 2.2 | Middleware `ResolveTelegramUser` | Extract telegram_id, resolve/firstOrCreate user, inject `auth_user` | `app/Http/Middleware/ResolveTelegramUser.php` |
| 2.3 | Rate limiter di `bootstrap/app.php` | `RateLimiter::for('telegram', ...)` — 30 req/min per user | `bootstrap/app.php` |
| 2.4 | Telegram webhook route | `POST /telegram/webhook` → `TelegramController@handle`, tanpa CSRF, throttle middleware | `routes/web.php` |
| 2.5 | CSRF exception | Tambahkan `/telegram/webhook` ke `$except` di `VerifyCsrfToken` | `bootstrap/app.php` |

### Fase 3: Stock Price API Integration (2 jam)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 3.1 | `StockPriceService` — `fetchPrice()` | HTTP ke Yahoo Finance v8, parse JSON, error handling, retry | `app/Services/StockPriceService.php` |
| 3.2 | `StockPriceService` — `fetchAllActiveTickers()` | Dedup ticker across all users, cache check, delay anti rate-limit | Same file |
| 3.3 | Unit test `fetchPrice()` | Mock HTTP response, test parsing, test error handling | `tests/Unit/Services/StockPriceServiceTest.php` |
| 3.4 | Artisan command `stocks:fetch-prices` | Integrasi StockPriceService + simpan price_history | `app/Console/Commands/StocksFetchPrices.php` |
| 3.5 | Test command manual | `php artisan stocks:fetch-prices`, verifikasi data di `price_history` | - |

### Fase 4: Telegram Bot — Core Commands (2-3 jam)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 4.1 | `TelegramController` — webhook handler | Terima update, parse message/callback, delegate ke BotService | `app/Http/Controllers/TelegramController.php` |
| 4.2 | `TelegramBotService` — message router | Parse text → extract command → route ke handler method | `app/Services/TelegramBotService.php` |
| 4.3 | Handler `/start` | Auto-register user, welcome message engaging + quick start suggestions | Same file |
| 4.4 | Handler `/help` | List commands dengan deskripsi ringkas | Same file |
| 4.5 | Handler `/track <ticker>` | Validasi format ticker, cek limit, insert tracked_stocks, fetch & tampilkan harga terkini | Same file |
| 4.6 | Handler `/untrack <ticker>` | Validasi ownership, soft-delete (active=false) atau hard delete | Same file |
| 4.7 | Handler `/watchlist` | Join tracked_stocks + price_history, tampilkan tabel: ticker, price, change% | Same file |
| 4.8 | Handler `/alert <ticker> <harga> <direction>` | Validasi: ticker harus sudah di-track, target harga valid, cek limit alert | Same file |
| 4.9 | Handler `/alerts` | List semua alert milik user: ID, ticker, target, direction, status | Same file |
| 4.10 | Handler `/cancela <id>` | Validasi ownership alert, delete/soft-delete | Same file |
| 4.11 | Handler `/price <ticker>` | Fetch harga real-time 1 ticker, tampilkan | Same file |
| 4.12 | Handler `/stats` | Query statistik user: total track, alert, notifikasi | Same file |

### Fase 5: Alert Engine + Notification (1.5 jam)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 5.1 | `AlertEvaluatorService` — `evaluate()` | Cross-over logic: `previousPrice > target >= currentPrice` (bawah), `previousPrice < target <= currentPrice` (atas) | `app/Services/AlertEvaluatorService.php` |
| 5.2 | `NotificationService` — `sendTelegram()` | Kirim pesan via Telegram Bot API ke chat_id user | `app/Services/NotificationService.php` |
| 5.3 | Integrasi ke `stocks:fetch-prices` | Setelah fetch + simpan, panggil AlertEvaluator untuk ticker yang harga berubah | `StocksFetchPrices.php` |
| 5.4 | Logging + update status | INSERT notification_log, UPDATE price_alerts.is_triggered | `AlertEvaluatorService.php` |
| 5.5 | Unit test AlertEvaluator | Test cross-over logic: trigger & non-trigger scenarios | `tests/Unit/Services/AlertEvaluatorServiceTest.php` |

### Fase 6: Rate Limiting & Abuse Prevention (1 jam)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 6.1 | Throttle middleware aktif | Verify `throttle:telegram` berfungsi — test spam command | `bootstrap/app.php` |
| 6.2 | Max ticker per user check | Di handler `/track`: cek `count >= config('stock_alert.limits.max_tickers_per_user')` | `TelegramBotService.php` |
| 6.3 | Max alert per user check | Di handler `/alert`: cek limit | `TelegramBotService.php` |
| 6.4 | Rate limit log (opsional) | Log setiap kali user kena throttle — untuk monitoring abuse pattern | `NotificationLog` atau log file |
| 6.5 | Error response friendly | Kalau kena limit: "⚠️ Kamu terlalu cepat. Coba lagi dalam 1 menit." | `TelegramBotService.php` |

### Fase 7: Scheduler & Queue Setup (30 menit)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 7.1 | Register schedule di `routes/console.php` | `stocks:fetch-prices` everyFiveMinutes, weekdays, 09:00-16:00, withoutOverlapping | `routes/console.php` |
| 7.2 | Setup server cron | `crontab -e` → `* * * * * cd /path && php artisan schedule:run` | Server crontab |
| 7.3 | Test scheduler end-to-end | Tunggu 5 menit, cek log, pastikan data masuk | - |
| 7.4 | Queue migration (scale) | `php artisan queue:table`, `php artisan migrate` — siap untuk future scale | DB |

### Fase 8: Multi-User Testing (1 jam)

| ID | Task | Detail |
|----|------|--------|
| 8.1 | **Data isolation test** | User A track BBCA.JK, User B track BBCA.JK. Verifikasi: watchlist User A ≠ watchlist User B |
| 8.2 | **Alert isolation test** | User A alert BBCA.JK 8000, User B alert BBCA.JK 9000. Verifikasi: masing-masing dapat alert sesuai threshold sendiri |
| 8.3 | **Dedup API call test** | 10+ user track ticker yang sama → cek log: API call ke Yahoo Finance hanya 1x per ticker |
| 8.4 | **Rate limit test** | Spam `/track` 30x dalam 1 menit → verifikasi throttle aktif |
| 8.5 | **Cross-over spam test** | Harga flapping di sekitar threshold → verifikasi alert hanya trigger 1x (tidak spam) |
| 8.6 | **Edge cases** | Ticker invalid, user belum `/start` tapi kirim command, command format salah, user tidak punya alert tapi `/alerts` |

### Fase 9: Deployment (1 jam)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 9.1 | Setup server environment | PHP 8.2+, MySQL, Composer, Git, Node.js (untuk Vite build nanti) | - |
| 9.2 | Clone & install | `git clone`, `composer install --no-dev`, `.env` production, `php artisan key:generate` | - |
| 9.3 | Apache/Nginx virtual host | Document root → `public/`, AllowOverride All, rewrite module | `/etc/apache2/sites-available/stock-alert.conf` |
| 9.4 | Fix permissions | `chown -R deahermes:www-data storage bootstrap/cache`, `chmod -R 775 storage bootstrap/cache`, `chmod o+x /home/deahermes` | - |
| 9.5 | Setup SSL via Tailscale Funnel | `sudo tailscale funnel 80` → HTTPS otomatis | - |
| 9.6 | Register Telegram webhook | `curl POST setWebhook?url=https://host.ts.net/telegram/webhook` | - |
| 9.7 | Setup Supervisor (queue worker) | Config queue worker, `supervisorctl reread/update/start` | `/etc/supervisor/conf.d/stock-alert-worker.conf` |
| 9.8 | Smoke test production | `/start`, `/track`, `/alert`, tunggu scheduler, cek notifikasi | - |

### Fase 10: Web Dashboard + Admin (Iterasi 2, 4-6 jam)

| ID | Task | Detail | File Output |
|----|------|--------|-------------|
| 10.1 | Setup Inertia + React + Tailwind | Install packages, Vite config, app.jsx entry | `resources/js/` |
| 10.2 | User Dashboard page | Ringkasan portfolio, statistik | `resources/js/Pages/Dashboard.jsx` |
| 10.3 | Watchlist page | Tabel + sparkline chart per ticker | `resources/js/Pages/Watchlist.jsx` |
| 10.4 | Alerts CRUD page | Form tambah/edit/delete alert via Inertia | `resources/js/Pages/Alerts.jsx` |
| 10.5 | Ticker detail + chart | Chart.js line chart harga historis | `resources/js/Pages/TickerDetail.jsx` |
| 10.6 | Notification history page | Tabel log + filter | `resources/js/Pages/History.jsx` |
| 10.7 | Admin Overview page | Total user, active user, popular tickers, system health | `resources/js/Pages/Admin/Overview.jsx` |
| 10.8 | Admin User List page | Tabel user dengan search + sort | `resources/js/Pages/Admin/Users.jsx` |
| 10.9 | Admin Popular Tickers | Top 20 ticker chart | `resources/js/Pages/Admin/Tickers.jsx` |
| 10.10 | Auth integration | Telegram login widget atau magic link | `app/Http/Controllers/Auth/TelegramAuthController.php` |

---

## 6. Deployment Plan

### 6.1 Server Architecture

```
┌──────────────────────────────────────────────────────────┐
│              Ubuntu 24.04 (dea-geekom)                    │
│                                                          │
│  ┌──────────────────────────────────────────────────┐    │
│  │  Tailscale Funnel (HTTPS auto)                    │    │
│  │  https://dea-geekom.tailxxxx.ts.net → port 80     │    │
│  └──────────────────┬───────────────────────────────┘    │
│                     ▼                                     │
│  ┌──────────────────────────────────────────────────┐    │
│  │  Apache 2.4 / Nginx                              │    │
│  │  Virtual host → /home/deahermes/stock-alert/public│    │
│  └──────────────────┬───────────────────────────────┘    │
│                     ▼                                     │
│  ┌──────────────────────────────────────────────────┐    │
│  │  PHP-FPM 8.2 (stock-alert pool)                  │    │
│  └──────────────────┬───────────────────────────────┘    │
│                     ▼                                     │
│  ┌──────────────────────────────────────────────────┐    │
│  │  Laravel 11 App                                  │    │
│  │  /home/deahermes/stock-alert/                    │    │
│  │  ├── app/                                        │    │
│  │  ├── config/stock_alert.php                      │    │
│  │  ├── routes/console.php  (scheduler)             │    │
│  │  └── .env                  (production)          │    │
│  └──────┬──────────────────┬───────────────────────┘    │
│         │                  │                             │
│         ▼                  ▼                             │
│  ┌──────────┐    ┌────────────────────┐                 │
│  │ MySQL 8  │    │ Supervisor          │                 │
│  │ stock_   │    │ ├─ queue worker (2) │                 │
│  │ alert DB │    │ └─ (opt) horizon   │                 │
│  └──────────┘    └────────────────────┘                 │
│                                                          │
│  Cron: * * * * * php artisan schedule:run                │
└──────────────────────────────────────────────────────────┘
```

### 6.2 Environment Variables (`.env` production)

```env
APP_NAME="Stock Alert"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dea-geekom.tailxxxx.ts.net
FORCE_HTTPS=true

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stock_alert
DB_USERNAME=stock_alert
DB_PASSWORD=<secure-password>

TELEGRAM_BOT_TOKEN=123456:ABC-DEF1234ghijkl
TELEGRAM_WEBHOOK_URL=https://dea-geekom.tailxxxx.ts.net/telegram/webhook

QUEUE_CONNECTION=database

# Stock Alert config
MAX_TICKERS_PER_USER=50
MAX_ALERTS_PER_USER=30
TELEGRAM_RATE_PER_MIN=30
FETCH_CACHE_TTL=60
FETCH_DELAY_MS=200
YAHOO_TIMEOUT=10
```

### 6.3 Quick Deploy Checklist

```bash
# 1. Server prep
sudo apt install php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl

# 2. Clone
cd /home/deahermes
git clone <repo-url> stock-alert
cd stock-alert

# 3. Install dependencies
composer install --no-dev --optimize-autoloader

# 4. Config
cp .env.example .env
# Edit .env: DB, TELEGRAM_BOT_TOKEN, APP_URL
php artisan key:generate

# 5. Database
php artisan migrate --force

# 6. Permissions
sudo chown -R deahermes:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod o+x /home/deahermes

# 7. Apache vhost (point DocumentRoot ke public/)
sudo cp apache-vhost.conf /etc/apache2/sites-available/stock-alert.conf
sudo a2ensite stock-alert.conf
sudo systemctl reload apache2

# 8. Tailscale Funnel
sudo tailscale funnel 80

# 9. Register webhook
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=<WEBHOOK_URL>"

# 10. Cron
crontab -e
# * * * * * cd /home/deahermes/stock-alert && php artisan schedule:run >> /dev/null 2>&1

# 11. Supervisor
sudo cp stock-alert-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

### 6.4 Apache Virtual Host

```apache
<VirtualHost *:80>
    ServerName dea-geekom.tailxxxx.ts.net
    DocumentRoot /home/deahermes/stock-alert/public

    <Directory /home/deahermes/stock-alert/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/stock-alert-error.log
    CustomLog ${APACHE_LOG_DIR}/stock-alert-access.log combined
</VirtualHost>
```

---

## 7. Multi-User Testing Checklist

- [ ] User A register via `/start` — data masuk tabel `users`
- [ ] User B register via `/start` — user_id berbeda, data terpisah
- [ ] User A track BBCA.JK, User B track BBCA.JK — dua row di `tracked_stocks` dengan user_id berbeda
- [ ] User A `/watchlist` hanya menampilkan ticker User A
- [ ] User B `/watchlist` hanya menampilkan ticker User B
- [ ] User A `/alerts` tidak bocor ke alert User B
- [ ] User A alert BBCA.JK 8000, User B alert BBCA.JK 9000 — masing-masing trigger independent
- [ ] User A spam `/track` 30x dalam 1 menit → throttle, response error friendly
- [ ] User A coba track > 50 ticker → ditolak dengan pesan limit
- [ ] User A coba `/cancela` untuk alert User B → ditolak (ownership validation)
- [ ] 10+ user track ticker yang sama → scheduler hanya fetch API 1x (lihat log/network)
- [ ] Alert trigger hanya sekali — tidak spam saat harga flapping
- [ ] `/stats` User A hanya menampilkan statistik User A sendiri

---

## 8. Estimasi Waktu

| Fase | Deskripsi | Estimasi |
|------|----------|----------|
| 0 | Setup Project | 1 jam |
| 1 | Database & Models | 30 menit |
| 2 | Config & Foundation | 30 menit |
| 3 | Stock Price API Integration | 2 jam |
| 4 | Telegram Bot Commands | 2-3 jam |
| 5 | Alert Engine + Notification | 1.5 jam |
| 6 | Rate Limiting & Abuse Prevention | 1 jam |
| 7 | Scheduler & Queue Setup | 30 menit |
| 8 | Multi-User Testing | 1 jam |
| 9 | Deployment | 1 jam |
| **Total MVP** | | **~11-12 jam** |
| 10 | Web Dashboard + Admin (Iterasi 2) | 4-6 jam |

---

## Ringkasan: What Success Looks Like

**Setelah Fase 0-9 (MVP) selesai, aplikasi ini:**

- ✅ Melayani **multiple user** secara bersamaan, data terisolasi per user
- ✅ User auto-register via `/start` dengan onboarding yang engaging
- ✅ User bisa track saham IDX (`.JK`) + US stocks dari Telegram
- ✅ User bisa set alert harga dengan threshold + direction (atas/bawah)
- ✅ Scheduler fetch harga otomatis setiap 5 menit — **deduplicate ticker, bukan N user × M ticker**
- ✅ Cross-over logic: alert trigger hanya saat harga menembus threshold (tidak spam)
- ✅ Notifikasi via Telegram dengan format informatif
- ✅ **Rate limiting:** 30 req/menit per user, max 50 ticker, max 30 alert per user
- ✅ `/stats` command: user lihat statistik penggunaan sendiri
- ✅ Semua notification dan price history tercatat rapi di database
- ✅ Berjalan di server Ubuntu dea-geekom dengan Supervisor + Tailscale Funnel

**Yang sengaja ditunda (untuk menjaga fokus MVP):**
- ❌ Web dashboard (React + Inertia) — Iterasi 2
- ❌ Admin dashboard — Iterasi 2
- ❌ Monetisasi / payment / billing — nanti
- ❌ AI / sentiment analysis — overkill
- ❌ Alert berbasis persentase — nice-to-have
- ❌ Daily summary — nice-to-have

---

*Plan ini direstrukturisasi untuk multi-user SaaS. Semua file path konkret, code snippets siap copy-paste, dan task breakdown bisa langsung dieksekusi. Gas, Iwan! 🚀*
