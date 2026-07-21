# Stock Alert — Rencana Implementasi (MVP — Multi-User SaaS)

> **Dibuat:** 2026-07-21
> **Untuk:** Iwan — developer Laravel + React + Inertia
> **Target user:** Multi-user — trader saham yang mau pantau harga + terima alert via Telegram
> **Server target:** Ubuntu (dea-geekom)
> **Prinsip:** MVP dulu, simpel, siap multi-user, siap scale (monetisasi nanti).

---

## Daftar Isi

1. [Konsep & Arsitektur](#1-konsep--arsitektur)
2. [Tech Stack Spesifik](#2-tech-stack-spesifik)
3. [Database Schema](#3-database-schema)
4. [UX / Flow Description](#4-ux--flow-description)
5. [Task Breakdown](#5-task-breakdown)
6. [Deployment Plan](#6-deployment-plan)
7. [Estimasi Waktu](#7-estimasi-waktu)

---

## 1. Konsep & Arsitektur

### 1.1 Gambaran Sistem

Stock Alert adalah aplikasi **multi-user SaaS** berbasis Laravel + Telegram Bot. User berinteraksi melalui **Telegram bot** (untuk set alert dan terima notifikasi) dan **web dashboard** (untuk lihat portfolio, riwayat alert, kelola setting). Aplikasi didesain dari awal untuk melayani banyak user sekaligus — setiap user punya data terisolasi (watchlist, alert, history sendiri-sendiri).

```
┌──────────────────────────────────────────────────────┐
│              Multiple Telegram Users                  │
│  User A: /track BBCA.JK, /alert BBCA.JK 8000        │
│  User B: /track TLKM.JK, /alert TLKM.JK 4000        │
│  User C: /track AAPL, /alert AAPL 300               │
└────────────────────┬─────────────────────────────────┘
                     │ (Telegram Bot API)
                     ▼
┌──────────────────────────────────────────────────────┐
│          Stock Alert — Laravel App (SaaS)             │
│                                                      │
│  ┌─────────────┐   ┌──────────────┐  ┌────────────┐ │
│  │ Telegram    │   │ Web Dashboard│  │ Artisan    │ │
│  │ Bot Handler │   │ (React+      │  │ Scheduler  │ │
│  │ (Webhook)   │   │  Inertia)    │  │ (Cron)     │ │
│  └──────┬──────┘   └──────┬───────┘  └─────┬──────┘ │
│         │                 │                 │        │
│         └────────┬────────┴─────────────────┘        │
│                  ▼                                    │
│         ┌───────────────┐                            │
│         │  Price Engine │  (Fetch + Compare + Alert)  │
│         └───────┬───────┘                            │
│                 │                                    │
│         ┌───────┴───────┐                            │
│         │    MySQL      │  (Data per-user terisolasi) │
│         └───────────────┘                            │
└──────────────────────┬───────────────────────────────┘
                       │ (HTTPS / API)
                       ▼
           ┌───────────────────────┐
           │ Yahoo Finance v8 API  │
           │ (Gratis, support IDX) │
           └───────────────────────┘
```

### 1.2 Flow Utama

#### A. Fetch Harga Saham (Setiap 5-15 menit via cron)

```
┌────────────┐     ┌──────────────┐     ┌──────────────┐
│ Scheduler  │────▶│  Fetch Job   │────▶│ Stock API    │
│ (cron)     │     │  (dispatched │     │ (Yahoo       │
│            │     │   ke queue)  │     │  Finance)    │
└────────────┘     └──────┬───────┘     └──────┬───────┘
                          │                    │
                          │  ◀── Harga ────────┘
                          ▼
                   ┌──────────────┐
                   │ Simpan ke    │
                   │ price_history│
                   └──────┬───────┘
                          │
                          ▼
                   ┌──────────────┐
                   │ Bandingkan   │
                   │ dgn threshold │
                   │ tiap user     │
                   └──────┬───────┘
                          │
                    ┌─────┴─────┐
                    │ Triggered? │
                    └─────┬─────┘
                     Ya   │   Tidak → selesai
                          ▼
                   ┌──────────────┐
                   │ Kirim Alert  │
                   │ via Telegram │
                   └──────────────┘
```

**Detail fetch & compare:**
1. Laravel Scheduler jalan setiap 5-15 menit (configurable).
2. Ambil semua **unique ticker** dari tabel `tracked_stocks` yang aktif.
3. Fetch harga terbaru via API (batch kalau bisa, satu-satu kalau API terbatas).
4. Simpan harga ke `price_history`.
5. Untuk setiap harga baru, cek semua `price_alerts` yang terkait ticker tersebut.
6. Kalau harga sekarang melampaui threshold (naik dari bawah ke atas threshold, atau turun dari atas ke bawah), trigger alert. **Pakai mekanisme "cross-over" agar alert tidak trigger berulang-ulang.**
7. Kirim notifikasi via Telegram Bot API.
8. Catat di `notification_log`.

#### B. User Interaction Flow

```
User kirim /start ──▶ Bot kenalkan diri & commands
User kirim /track BBCA.JK ──▶ Ticker masuk ke tracked_stocks
User kirim /alert BBCA.JK 8000 bawah ──▶ Alert tersimpan
                                     (alert kalau turun ke ≤ 8000)
User kirim /alert TLKM.JK 4000 atas ──▶ Alert tersimpan
                                     (alert kalau naik ke ≥ 4000)
... cron job jalan ...
Harga BBCA.JK mencapai 8000 ──▶ User terima notif Telegram:
                                "🔔 BBCA.JK turun ke Rp 8,000
                                 Harga sekarang: Rp 7,980"
```

### 1.3 Scheduler / Cron Design

Gunakan **Laravel Task Scheduling** (satu cron entry yang trigger Laravel scheduler):

```cron
* * * * * cd /path-to-stock-alert && php artisan schedule:run >> /dev/null 2>&1
```

Di `routes/console.php` (Laravel 11) atau `app/Console/Kernel.php`:

```php
// Fetch harga setiap 5 menit saat market buka (Senin-Jumat, 09:00-16:00 WIB)
$schedule->command('stocks:fetch-prices')
    ->everyFiveMinutes()
    ->weekdays()
    ->between('9:00', '16:00')
    ->withoutOverlapping();

// Alternatif fetch terus-menerus (termasuk after-hours dan weekend):
$schedule->command('stocks:fetch-prices')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
```

**Kenapa queue job?** Fetch batch ticker bisa lambat (API call per ticker). Dispatch ke Laravel queue (database driver, cukup untuk MVP) agar scheduler tidak block. Kalau mau lebih scalable bisa pakai Redis.

**Rekomendasi MVP:** Tidak perlu queue system dulu. Cukup scheduler → artisan command → fetch all & compare synchronously. ~20-50 ticker masih aman dalam 1 menit.

---

## 1A. Multi-User SaaS Considerations

### 1A.1 Data Isolation

Setiap user punya data sendiri — sudah terjamin lewat `user_id` FK di semua tabel. Tidak ada user yang bisa lihat data user lain.

```
User A: tracked_stocks.where(user_id = 1) → BBCA.JK, TLKM.JK
User B: tracked_stocks.where(user_id = 2) → GOTO.JK, AAPL
```

### 1A.2 Unique Constraint Per User

Sudah di-cover di schema: `$table->unique(['user_id', 'ticker'])` — satu user tidak bisa track ticker yang sama dua kali, tapi user berbeda bisa track ticker yang sama.

### 1A.3 Rate Limiting & Fair Usage

Untuk mencegah abuse saat banyak user:

| Mekanisme | Implementasi |
|-----------|-------------|
| **Rate limit Telegram commands** | Laravel throttle middleware: max 30 req/menit per user |
| **Batch API fetch** | Ambil daftar unique ticker dari SEMUA user → fetch satu kali per ticker → evaluasi alert masing-masing user. Jangan fetch per user. |
| **Max ticker per user (MVP)** | Belum perlu hard limit, tapi siapkan config `max_tickers_per_user` di `.env` untuk nanti |
| **Max alert per user** | Sama — config `max_alerts_per_user` |

### 1A.4 Optimasi Fetch untuk Multi-User

Flow yang benar:

```
scheduler runs every 5 min
    │
    ▼
1. Ambil semua unique ticker dari SEMUA user:
   SELECT DISTINCT ticker FROM tracked_stocks WHERE active = 1
   → ['BBCA.JK', 'TLKM.JK', 'AAPL', 'GOTO.JK']  (4 ticker, bukan 4 × N user)
    │
    ▼
2. Fetch harga per ticker (4x API call, bukan N×4)
    │
    ▼
3. Simpan harga ke price_history
    │
    ▼
4. Untuk setiap ticker yang harganya berubah,
   query semua alert dari semua user untuk ticker tersebut:
   SELECT * FROM price_alerts WHERE ticker = 'BBCA.JK' AND is_triggered = 0
    │
    ▼
5. Evaluasi + kirim alert per user
```

**Ini penting:** Jangan loop per-user lalu fetch API. Selalu deduplicate ticker dulu, fetch sekali, baru evaluasi per-user. Ini yang bikin skala dari 1 user ke 1000 user tanpa bom API.

### 1A.5 Onboarding User Baru

User auto-register saat pertama `/start`. Flow:

```
User baru: /start
Bot: "Halo! 🚀 Stock Alert siap bantu pantau saham.
      Mulai dengan:
      /track BBCA.JK — tambah saham ke watchlist
      /alert BBCA.JK 8000 bawah — set alert harga
      /help — lihat semua perintah"
      
      [DB: INSERT or UPDATE users]
```

**Nanti (untuk monetisasi):** Bisa tambah `/subscribe` command, cek quota, dsb. Tapi untuk MVP sekarang, semua user gratis unlimited.

### 1A.6 Multi-User Testing Checklist

- [ ] User A track BBCA.JK, User B track BBCA.JK → data terpisah
- [ ] User A set alert 8000, User B set alert 9000 → masing-masing dapat alert sendiri
- [ ] 10+ user track ticker yang sama → API hanya di-fetch 1x
- [ ] `/watchlist` User A tidak bocor ke User B
- [ ] Rate limiting: spam command → kena throttle

---

### 2.1 Backend

| Komponen | Rekomendasi | Alasan |
|----------|------------|--------|
| **Framework** | Laravel 11.x | User sudah expert, routing/ORM/queue/scheduler built-in |
| **PHP** | PHP 8.2+ | Minimum requirement Laravel 11 |
| **Database** | MySQL 8.x | User familiar, performa cukup untuk skala MVP |
| **Queue** | Database driver (default) | Tidak perlu Redis untuk MVP |
| **Scheduler** | Laravel Task Scheduling | Sudah built-in, tinggal 1 cron entry |

### 2.2 Stock Price API

| API | Kelebihan | Kekurangan | Rekomendasi |
|-----|----------|-----------|-------------|
| **Yahoo Finance (unofficial)** | Gratis, support `.JK` (IDX) + US stocks, real-time-ish | Tidak ada official API key, kadang rate-limited | ⭐ **Primary** |
| **Alpha Vantage** | Official REST API, gratis tier (25 req/hari) | Rate limit rendah untuk gratis | Secondary / fallback |
| **Polygon.io** | Data real-time, bagus | Berbayar (mulai $29/bln) | Opsional upgrade |

**Rekomendasi implementasi Yahoo Finance:**

**Option A — PHP package:** Gunakan package `scheb/yahoo-finance-api` (PHP wrapper Yahoo Finance v7/v8 API). Fetch via HTTP.

**Option B — Direct cURL ke Yahoo Finance v8:** Endpoint:
```
GET https://query1.finance.yahoo.com/v8/finance/chart/BBCA.JK?interval=1d&range=1d
```
Response JSON mengandung `regularMarketPrice`, `previousClose`, `regularMarketChange`, dsb.

**Option C — Shell out ke Python yfinance (kalau Option A & B bermasalah):**
```bash
python3 -c "import yfinance as yf; t=yf.Ticker('BBCA.JK'); print(t.fast_info.last_price)"
```
Simpel, reliable, tapi tambahan dependency Python.

**Rekomendasi MVP:** Gunakan **Option B** (direct HTTP ke Yahoo Finance v8) via Laravel HTTP Client (`Http::get()`). Tanpa package tambahan, tanpa dependency Python.

### 2.3 Telegram Bot

| Library | Kelebihan | Rekomendasi |
|---------|----------|-------------|
| **irazasyed/telegram-bot-send** | Paling populer di Laravel ecosystem, API lengkap, sudah support Laravel 11 | ⭐ **Primary** |
| **nutgram/laravel** | Modern, lebih ringan, support webhook + polling | Alternatif |
| **defstudio/telegraph** | Full-featured, GUI builder | Overkill utk MVP |

**Rekomendasi MVP:** `irazasyed/telegram-bot-send:~3.10` — simpel, banyak tutorial, Iwan mungkin sudah familiar.

```bash
composer require irazasyed/telegram-bot-send
```

### 2.4 Frontend (Web Dashboard)

| Komponen | Rekomendasi | Catatan |
|----------|------------|---------|
| **Framework** | React 18 + Inertia.js | User expert di stack ini |
| **CSS** | Tailwind CSS 3.x | Utility-first, cepat develop |
| **Chart** | Chart.js + react-chartjs-2 | Grafik harga saham simpel |
| **Build** | Vite (Laravel default) | Sudah bundled dgn Laravel 11 |

**Catatan MVP:** Web dashboard penting untuk SaaS (user management, onboarding, lihat status alert). Tapi prioritas utama iterasi 1 adalah **Telegram bot fully working** dulu — dashboard bisa dikerjakan setelah bot stabil dan ada beberapa user aktif.

### 2.5 Development Tools

| Tool | Kegunaan |
|------|----------|
| **Laravel Sail** (opsional) | Docker dev environment, tapi kalau udah ada MySQL lokal skip |
| **Laravel Pint** | Code style / formatting |
| **PHPStan / Larastan** | Static analysis (opsional, jangan bikin lambat MVP) |
| **Pest PHP** | Testing framework (lebih simpel dari PHPUnit, tapi opsional) |

---

## 3. Database Schema

### 3.1 ERD Sederhana

```
┌──────────────┐       ┌──────────────────┐       ┌──────────────┐
│    users     │       │  tracked_stocks  │       │ price_alerts │
├──────────────┤       ├──────────────────┤       ├──────────────┤
│ id           │──┐    │ id               │    ┌──│ id           │
│ telegram_id  │  │    │ user_id (FK)     │◄───┘  │ user_id (FK) │
│ telegram_    │  │    │ ticker           │       │ tracked_stock │
│   username   │  │    │ name (nama shm)  │       │   _id (FK)    │
│ first_name   │  ├───▶│ active (bool)    │       │ ticker        │
│ chat_id      │  │    │ created_at       │       │ target_price  │
│ is_active    │  │    │ updated_at       │       │ direction     │
│ created_at   │  │    └──────────────────┘       │   (atas/bawah)│
│ updated_at   │  │                               │ is_triggered  │
└──────────────┘  │                               │ triggered_at  │
                  │                               │ created_at    │
                  │                               │ updated_at    │
                  │                               └──────────────┘
                  │
                  │    ┌──────────────────┐       ┌──────────────┐
                  │    │  price_history   │       │notification  │
                  │    ├──────────────────┤       │   _log       │
                  │    │ id               │       ├──────────────┤
                  │    │ ticker           │       │ id           │
                  └───▶│ price            │       │ user_id (FK) │
                       │ change (abs)     │       │ price_alert  │
                       │ change_percent   │       │   _id (FK)    │
                       │ recorded_at      │       │ ticker       │
                       │ created_at       │       │ message      │
                       └──────────────────┘       │ sent_at      │
                                                  │ status       │
                                                  │ created_at   │
                                                  └──────────────┘
```

### 3.2 Migrations (Detail Kolom)

#### `users`

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->bigInteger('telegram_id')->unique();
    $table->string('telegram_username')->nullable();
    $table->string('first_name')->nullable();
    $table->bigInteger('chat_id');              // ID chat Telegram untuk kirim notif
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Catatan:** Untuk MVP, tidak perlu auth system Laravel biasa (Breeze/Jetstream). Auth via Telegram ID saja. User ter-registrasi otomatis saat pertama kali `/start`.

#### `tracked_stocks`

```php
Schema::create('tracked_stocks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('ticker', 20);               // BBCA.JK, TLKM.JK, AAPL, TSLA
    $table->string('name')->nullable();          // Nama saham: "Bank Central Asia Tbk."
    $table->boolean('active')->default(true);
    $table->timestamps();

    $table->unique(['user_id', 'ticker']);       // Satu user ga bisa track ticker sama 2x
    $table->index('ticker');                     // Buat query "siapa aja yg track ticker ini?"
});
```

#### `price_alerts`

```php
Schema::create('price_alerts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('tracked_stock_id')->constrained()->cascadeOnDelete();
    $table->string('ticker', 20);                // Denormalisasi biar query gampang
    $table->decimal('target_price', 15, 2);
    $table->enum('direction', ['atas', 'bawah']); // 'atas' = alert kalau naik ke ≥ target
                                                   // 'bawah' = alert kalau turun ke ≤ target
    $table->boolean('is_triggered')->default(false);
    $table->timestamp('triggered_at')->nullable();
    $table->timestamps();
});
```

**Logika trigger:**
- `direction = 'bawah'` + `target_price = 8000`: alert kalau harga terkini ≤ 8000 dan harga sebelumnya > 8000 (baru turun menembus)
- `direction = 'atas'` + `target_price = 300`: alert kalau harga terkini ≥ 300 dan harga sebelumnya < 300 (baru naik menembus)

**Reset alert:** Setelah alert trigger, `is_triggered = true`. User bisa re-enable via command `/alert BBCA.JK 8000 bawah` lagi (update record atau insert baru kalau beda harga).

#### `price_history`

```php
Schema::create('price_history', function (Blueprint $table) {
    $table->id();
    $table->string('ticker', 20);
    $table->decimal('price', 15, 2);
    $table->decimal('change', 15, 2)->nullable();         // Selisih harga
    $table->decimal('change_percent', 8, 4)->nullable();  // Persentase perubahan
    $table->timestamp('recorded_at');                     // Kapan harga ini tercatat
    $table->timestamps();

    $table->index(['ticker', 'recorded_at']);
});
```

**Retention:** Untuk MVP simpan aja terus. Kalau tabel membesar, nanti bisa tambah scheduled task untuk hapus data > 90 hari.

#### `notification_log`

```php
Schema::create('notification_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('price_alert_id')->nullable()->constrained()->nullOnDelete();
    $table->string('ticker', 20);
    $table->text('message');
    $table->enum('status', ['sent', 'failed'])->default('sent');
    $table->text('error_message')->nullable();   // Kalau gagal kirim
    $table->timestamp('sent_at')->nullable();
    $table->timestamps();
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

### 4.1 Telegram Bot Commands (Prioritas Utama)

Bot akan menggunakan **webhook mode** (lebih cepat dari long polling). Setup webhook URL ke endpoint Laravel.

| Command | Deskripsi | Contoh |
|---------|----------|--------|
| `/start` | Register user, tampilkan welcome + list commands | Bot: "Halo Iwan! 🚀 Stock Alert siap. Gunakan /help untuk lihat commands." |
| `/help` | Tampilkan semua commands | |
| `/track <ticker>` | Tambah ticker ke watchlist | `/track BBCA.JK` → "✅ BBCA.JK ditambahkan ke watchlist." |
| `/untrack <ticker>` | Hapus ticker dari watchlist | `/untrack BBCA.JK` → "❌ BBCA.JK dihapus dari watchlist." |
| `/watchlist` | List semua ticker yang di-track | Tabel: Ticker, Harga Terkini, Change% |
| `/alert <ticker> <harga> <atas/bawah>` | Set alert threshold | `/alert BBCA.JK 8000 bawah` → "🔔 Alert diset: BBCA.JK turun ke Rp 8.000" |
| `/alerts` | List semua alert aktif | Tabel: Ticker, Target, Direction, Status |
| `/cancela <alert_id>` | Cancel alert spesifik | `/cancela 3` → "Alert #3 dicancel." |
| `/price <ticker>` | Cek harga terkini | `/price BBCA.JK` → "BBCA.JK: Rp 8,250 (+1.5%)" |

**Flow registrasi user (auto via /start):**

```
User: /start
Bot:  "Halo! 👋 Stock Alert siap membantu pantau saham.
      Kamu sudah terdaftar. Mulai dengan:
      /track BBCA.JK — tambah saham ke watchlist
      /help — lihat semua perintah"
      
      [Di belakang layar: insert/update user ke DB]
```

### 4.2 Notifikasi Alert (Format)

```
🔔 ALERT: BBCA.JK MENCAPAI TARGET

Harga turun ke Rp 8,000 (target kamu)
Harga sekarang: Rp 7,980
Perubahan hari ini: -3.2%

/detail_BBCA.JK  |  /alerts
```

### 4.3 Web Dashboard (Nice-to-have, Iterasi 2)

**Halaman-halaman:**
1. **Dashboard Home** — Ringkasan portfolio: jumlah ticker, alert aktif, notifikasi terbaru
2. **Watchlist** — Tabel ticker dengan harga real-time, sparkline chart kecil
3. **Alerts** — Semua alert, bisa tambah/edit/delete via UI
4. **History** — Log notifikasi, grafik harga historis per ticker

**Auth web dashboard:** Karena user sudah ter-registrasi via Telegram, web dashboard bisa pake **magic link** atau **OTP via Telegram**:

```
User buka web dashboard → masukkan Telegram username
→ Bot kirim OTP/link ke Telegram
→ User verify → session login
```

Atau lebih simpel: web dashboard tidak perlu login khusus, cukup lihat data publik / shareable. Atau pakai Laravel Socialite + Telegram login.

**Rekomendasi MVP:** Skip web dashboard dulu. Fokus 100% ke Telegram bot yang solid.

### 4.4 Fitur Tambahan (Future / Iterasi 3+)

- Alert dengan kondisi persentase (`/alert BBCA.JK -5%` — alert kalau turun 5%)
- Daily summary: kirim ringkasan portfolio tiap pagi jam 8 via Telegram
- Multiple recipients (group chat support)
- Webhook untuk trigger alert ke sistem lain
- Screener sederhana: "cari saham yang turun > 3% hari ini"
- Support cryptocurrency (BTC-USD, ETH-USD)

---

## 5. Task Breakdown

### Fase 0: Setup Project (Estimasi: 1 jam)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 0.1 | **Create Laravel project** | `composer create-project laravel/laravel stock-alert` di server dea-geekom | Laravel 11 installed |
| 0.2 | **Setup MySQL database** | Buat database `stock_alert`, setup `.env` | Koneksi DB OK |
| 0.3 | **Install Telegram Bot SDK** | `composer require irazasyed/telegram-bot-send` | Package terinstall |
| 0.4 | **Konfigurasi bot** | Setup `TELEGRAM_BOT_TOKEN` di `.env`, daftarkan webhook URL | Bot responsif |
| 0.5 | **Init git repo & first commit** | `git init`, `.gitignore`, initial commit | Repo siap |

### Fase 1: Database & Models (Estimasi: 30 menit)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 1.1 | **Migration: users** | `php artisan make:migration create_users_table`, isi schema dari section 3.2 | Table `users` |
| 1.2 | **Migration: tracked_stocks** | Schema + unique index (user_id, ticker) | Table `tracked_stocks` |
| 1.3 | **Migration: price_alerts** | Schema + FK ke users & tracked_stocks | Table `price_alerts` |
| 1.4 | **Migration: price_history** | Schema + composite index | Table `price_history` |
| 1.5 | **Migration: notification_logs** | Schema | Table `notification_logs` |
| 1.6 | **Models + Relationships** | User, TrackedStock, PriceAlert, PriceHistory, NotificationLog dengan Eloquent relationships | Models siap |
| 1.7 | **Run migration** | `php artisan migrate` | Semua tabel terbentuk |

### Fase 2: Stock Price API Integration (Estimasi: 1-2 jam)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 2.1 | **Buat service class `StockPriceService`** | Di `app/Services/StockPriceService.php`, method `fetchPrice(ticker)` → return array `[price, change, changePercent]` | Service class |
| 2.2 | **Implement Yahoo Finance fetcher** | HTTP GET ke Yahoo Finance v8 API, parse JSON, error handling (rate limit, ticker not found) | Fetch harga berfungsi |
| 2.3 | **Implement fallback mekanisme** | Kalau Yahoo gagal → try Alpha Vantage (kalau ada API key) → return error | Robust fetch |
| 2.4 | **Buat artisan command `stocks:fetch-prices`** | Ambil semua unique ticker dari `tracked_stocks` yang aktif, fetch harga, simpan ke `price_history` | Command siap |
| 2.5 | **Test command manual** | `php artisan stocks:fetch-prices`, cek data di `price_history` | Harga tersimpan benar |

### Fase 3: Telegram Bot — User Interaction (Estimasi: 2-3 jam)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 3.1 | **Setup Telegram webhook route** | Route `POST /telegram/webhook` (tanpa CSRF middleware) | Endpoint siap |
| 3.2 | **Buat Telegram Controller** | `app/Http/Controllers/TelegramController.php` dengan method `handleWebhook()` | Controller siap |
| 3.3 | **Buat Bot Service class** | `app/Services/TelegramBotService.php` — parse message, routing ke handler per command | Service siap |
| 3.4 | **Implement /start handler** | Register/update user, kirim welcome message | User auto-register |
| 3.5 | **Implement /track handler** | Validasi ticker (regex: 1-10 uppercase alphanumeric + optional `.JK`), insert ke tracked_stocks, kirim konfirmasi | Track berfungsi |
| 3.6 | **Implement /untrack handler** | Hapus dari tracked_stocks, kirim konfirmasi | Untrack berfungsi |
| 3.7 | **Implement /watchlist handler** | Query tracked_stocks + latest price dari price_history, format tabel | Watchlist berfungsi |
| 3.8 | **Implement /alert handler** | Parse `ticker`, `harga`, `direction`, validasi, simpan ke price_alerts, konfirmasi | Alert tersimpan |
| 3.9 | **Implement /alerts handler** | List semua alert user yang belum triggered | List alert berfungsi |
| 3.10 | **Implement /cancela handler** | Cancel alert by ID (validasi kepemilikan) | Cancel berfungsi |
| 3.11 | **Implement /price handler** | Fetch harga terkini untuk 1 ticker, tampilkan | Cek harga berfungsi |
| 3.12 | **Implement /help handler** | List semua commands dengan deskripsi | Help berfungsi |

### Fase 4: Alert Engine (Estimasi: 1-2 jam)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 4.1 | **Buat service class `AlertEvaluatorService`** | Method `evaluate(ticker, currentPrice, previousPrice)` → cek semua alert non-triggered untuk ticker tsb | Service siap |
| 4.2 | **Implement cross-over logic** | `direction=bawah`: trigger kalau `previousPrice > target >= currentPrice`. `direction=atas`: trigger kalau `previousPrice < target <= currentPrice` | Logic benar |
| 4.3 | **Integrasi ke `stocks:fetch-prices`** | Setelah fetch & simpan harga, panggil `AlertEvaluatorService` untuk setiap ticker yang harganya berubah | Evaluation jalan |
| 4.4 | **Implement send alert via Telegram** | `NotificationService::sendAlert(user, alert, currentPrice)` — format pesan, kirim via Telegram Bot API | Notif terkirim |
| 4.5 | **Log notification** | Setelah kirim, insert ke `notification_logs`, update `price_alerts.is_triggered = true` | Log tercatat |
| 4.6 | **Prevent duplicate alert** | Logic: jangan kirim alert lagi kalau harga masih di sekitar threshold yang sama (gunakan `is_triggered` flag) | Tidak spam |

### Fase 5: Scheduler Setup (Estimasi: 15 menit)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 5.1 | **Register command di Kernel** | Di `routes/console.php`, schedule `stocks:fetch-prices` everyFiveMinutes() | Schedule terdaftar |
| 5.2 | **Setup server cron** | `crontab -e` → tambah entry `* * * * * php /path/artisan schedule:run` | Cron aktif |
| 5.3 | **Test scheduler** | Tunggu interval, cek log, pastikan fetch jalan otomatis | Otomatisasi OK |

### Fase 6: Web Dashboard (Nice-to-have, Iterasi 2, Estimasi: 3-4 jam)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 6.1 | **Setup Inertia + React stack** | Install Inertia, React, Tailwind (Laravel Breeze Inertia atau manual) | Frontend scaffold |
| 6.2 | **Dashboard page** | Ringkasan: total ticker, alert aktif, harga terkini | Halaman dashboard |
| 6.3 | **Watchlist page** | Tabel semua ticker user, harga real-time | Halaman watchlist |
| 6.4 | **Alerts page** | CRUD alert via UI (pakai Inertia forms) | Halaman alert |
| 6.5 | **Price chart component** | Chart.js line chart: harga vs waktu (data dari price_history) | Grafik interaktif |

**Catatan:** Fase 6 tidak perlu dikerjakan di iterasi MVP. Fokus selesaikan Fase 0-5 dulu.

### Fase 7: Testing & Polish (Estimasi: 1 jam)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 7.1 | **Test scenario 1: Track + alert triggered** | `/track BBCA.JK`, `/alert BBCA.JK 1000000 bawah` (harga target jauh di atas supaya pasti trigger) | Alert sukses |
| 7.2 | **Test scenario 2: Track + alert not triggered** | Set target harga yang tidak mungkin (misal 1 rupiah), pastikan tidak trigger | Tidak false alert |
| 7.3 | **Test scenario 3: Multiple users** | 2 user berbeda track ticker sama, alert berbeda → pastikan isolasi | Multi-user OK |
| 7.4 | **Test scenario 4: Edge cases** | Ticker invalid, format command salah, user belum /start | Error handling OK |
| 7.5 | **Code cleanup** | `php artisan pint`, hapus komentar/debug log | Code bersih |

### Fase 8: Deployment (Estimasi: 30-60 menit)

| ID | Task | Detail | Output |
|----|------|--------|--------|
| 8.1 | **Setup server (dea-geekom)** | Pastikan PHP 8.2+, MySQL, Composer, Git tersedia | Server siap |
| 8.2 | **Clone repo & install** | `git clone`, `composer install`, `cp .env.example .env`, edit .env | App terinstall |
| 8.3 | **Setup Nginx/Apache** | Virtual host, document root ke `public/`, SSL (Let's Encrypt) | App bisa diakses |
| 8.4 | **Set webhook** | `php artisan telegram:set-webhook` atau manual curl ke Telegram API | Bot via webhook |
| 8.5 | **Setup supervisor (opsional)** | Untuk queue worker kalau pakai queue. Skip untuk MVP. | - |
| 8.6 | **Smoke test** | Test semua commands via Telegram, pastikan scheduler jalan | Production OK |

---

## 6. Deployment Plan

### 6.1 Server Architecture (Simple)

```
┌──────────────────────────────────────────┐
│         Ubuntu Server (dea-geekom)        │
│                                          │
│  ┌────────────┐    ┌──────────────────┐  │
│  │  Nginx     │───▶│  Laravel App     │  │
│  │  (port 80/ │    │  PHP-FPM 8.2     │  │
│  │   443)     │    │  /var/www/       │  │
│  └────────────┘    │  stock-alert     │  │
│                    └────────┬─────────┘  │
│                             │            │
│                    ┌────────┴─────────┐  │
│                    │  MySQL 8         │  │
│                    │  (localhost)     │  │
│                    └──────────────────┘  │
│                                          │
│  ┌──────────────────────────────────────┐│
│  │  Cron (Laravel Scheduler)            ││
│  │  * * * * * php artisan schedule:run  ││
│  └──────────────────────────────────────┘│
└──────────────────────────────────────────┘
```

### 6.2 Environment Variables (`.env`)

```env
APP_NAME="Stock Alert"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://stock-alert.domain-kamu.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stock_alert
DB_USERNAME=stock_alert
DB_PASSWORD=***

TELEGRAM_BOT_TOKEN=123456:ABC-DEF1234ghijkl
TELEGRAM_WEBHOOK_URL=https://stock-alert.domain-kamu.com/telegram/webhook

# Opsional
ALPHA_VANTAGE_API_KEY=
```

### 6.3 Telegram Webhook Setup

Setelah deploy, daftarkan webhook:

```bash
curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://stock-alert.domain-kamu.com/telegram/webhook"
```

---

## 7. Estimasi Waktu

| Fase | Deskripsi | Estimasi |
|------|----------|----------|
| 0 | Setup Project | 1 jam |
| 1 | Database & Models | 30 menit |
| 2 | Stock Price API Integration | 1-2 jam |
| 3 | Telegram Bot Commands | 2-3 jam |
| 4 | Alert Engine | 1-2 jam |
| 5 | Scheduler Setup | 15 menit |
| 7 | Testing & Polish | 1 jam |
| 8 | Deployment | 30-60 menit |
| **Total MVP** | | **~7-11 jam** |
| 6 | Web Dashboard (Iterasi 2) | 3-4 jam |

---

## Rekomendasi Arsitektur dari Claude Sonnet (Ringkasan)

Berdasarkan requirement dan konteks Iwan sebagai developer Laravel berpengalaman:

1. **Monolith dulu, microservice nanti.** Laravel sudah punya semua yang dibutuhkan (queue, scheduler, ORM, HTTP client) dalam satu framework. Tidak perlu extract service terpisah di awal.

2. **Telegram-first, web-second.** Bot Telegram adalah interface utama karena notifikasi adalah core feature. Dashboard web bisa menyusul setelah bot stabil.

3. **Database queue untuk MVP.** `QUEUE_CONNECTION=database` sudah cukup. Kalau nanti ticker banyak dan fetch jadi lambat, baru upgrade ke Redis + Horizon.

4. **Yahoo Finance adalah pilihan pragmatis.** Gratis, reliable, support IDX. Jangan over-invest di API berbayar sebelum ada user aktif.

5. **Simplicity > features.** Jangan tambah fitur "keren" (AI prediction, sentiment analysis, auto-trading) sebelum core loop (track → fetch → alert) solid.

6. **Testing ringan dulu.** Manual testing bot cukup untuk MVP. Unit test bisa ditambah setelah core stable, terutama di `AlertEvaluatorService` (logic cross-over rawan bug).

---

## Ringkasan: MVP Deliverable

Setelah Fase 0-5 + 7-8 selesai, yang didapat:

✅ User bisa register via `/start` di Telegram
✅ User bisa track saham IDX (`.JK`) dan US stocks
✅ User bisa set alert dengan threshold harga (atas/bawah)
✅ Scheduler fetch harga saham otomatis setiap 5-15 menit
✅ Notifikasi via Telegram saat harga menembus threshold
✅ Log notifikasi dan riwayat harga tersimpan di database
✅ App berjalan di server Ubuntu dea-geekom

**Yang belum (future iteration):**
- ❌ Web dashboard (React + Inertia)
- ❌ Grafik harga interaktif
- ❌ Alert berbasis persentase
- ❌ Daily summary
- ❌ Unit/feature tests

---

*Plan ini siap dieksekusi. Mulai dari Fase 0 — good luck, Iwan! 🚀*