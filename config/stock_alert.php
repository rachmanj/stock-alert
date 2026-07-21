<?php

return [
    'limits' => [
        'max_tickers_per_user' => env('MAX_TICKERS_PER_USER', 50),
        'max_alerts_per_user' => env('MAX_ALERTS_PER_USER', 30),
        'telegram_rate_per_min' => env('TELEGRAM_RATE_PER_MIN', 30),
    ],

    'fetch' => [
        'cache_ttl' => env('FETCH_CACHE_TTL', 60),       // detik, skip fetch kalau < TTL
        'delay_ms' => env('FETCH_DELAY_MS', 200),        // delay antar API call
        'yahoo_timeout' => env('YAHOO_TIMEOUT', 10),     // timeout HTTP
        'yahoo_base_url' => 'https://query1.finance.yahoo.com/v8/finance/chart',
    ],

    'popular_tickers' => [
        'idx' => ['BBCA.JK', 'TLKM.JK', 'ASII.JK', 'UNVR.JK', 'BMRI.JK'],
        'us' => ['AAPL', 'TSLA', 'NVDA', 'MSFT', 'GOOGL'],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    ],
];
