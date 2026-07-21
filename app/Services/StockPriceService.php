<?php

namespace App\Services;

use App\Models\PriceHistory;
use App\Models\TrackedStock;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class StockPriceService
{
    /**
     * Fetch the latest price for a ticker, using price_history cache when still fresh.
     *
     * @return array{price: float, change: float, change_percent: float, name: string|null}|array{error: string}
     */
    public function fetchPrice(string $ticker): array
    {
        $cached = $this->getCachedPrice($ticker);
        if ($cached !== null) {
            return $cached;
        }

        $url = config('stock_alert.fetch.yahoo_base_url')."/{$ticker}?interval=1d&range=1d";
        $response = $this->fetchFromYahoo($url);
        $data = $response->json();

        if (empty($data['chart']['result'][0])) {
            return ['error' => "Ticker '{$ticker}' not found"];
        }

        $meta = $data['chart']['result'][0]['meta'] ?? [];

        if (! isset($meta['regularMarketPrice'])) {
            return ['error' => "Ticker '{$ticker}' not found"];
        }

        $price = (float) $meta['regularMarketPrice'];
        $previousClose = (float) ($meta['previousClose'] ?? $price);
        $change = $price - $previousClose;
        $changePercent = $previousClose != 0
            ? round(($change / $previousClose) * 100, 2)
            : 0.0;

        return [
            'price' => $price,
            'change' => $change,
            'change_percent' => $changePercent,
            'name' => $meta['shortName'] ?? $meta['longName'] ?? null,
        ];
    }

    /**
     * Fetch prices for all unique active tickers across all users.
     *
     * @return array<string, array{price: float, change: float, change_percent: float, name: string|null}|array{error: string}>
     */
    public function fetchAllActiveTickers(): array
    {
        $tickers = TrackedStock::where('active', true)
            ->distinct()
            ->pluck('ticker');

        if ($tickers->isEmpty()) {
            return [];
        }

        $results = [];

        foreach ($tickers as $ticker) {
            try {
                $data = $this->fetchPrice($ticker);

                if (isset($data['error'])) {
                    $results[$ticker] = $data;
                } else {
                    PriceHistory::create([
                        'ticker' => $ticker,
                        'price' => $data['price'],
                        'change' => $data['change'],
                        'change_percent' => $data['change_percent'],
                        'recorded_at' => now(),
                    ]);

                    $results[$ticker] = $data;
                }
            } catch (\Exception $e) {
                $results[$ticker] = ['error' => $e->getMessage()];
            }

            usleep(config('stock_alert.fetch.delay_ms', 200) * 1000);
        }

        return $results;
    }

    /**
     * Return cached price data when the latest price_history row is still within TTL.
     *
     * @return array{price: float, change: float, change_percent: float, name: string|null}|null
     */
    private function getCachedPrice(string $ticker): ?array
    {
        $ttl = (int) config('stock_alert.fetch.cache_ttl', 60);

        $latest = PriceHistory::where('ticker', $ticker)
            ->orderByDesc('recorded_at')
            ->first();

        if ($latest === null || ! $latest->recorded_at->gte(now()->subSeconds($ttl))) {
            return null;
        }

        return [
            'price' => (float) $latest->price,
            'change' => (float) $latest->change,
            'change_percent' => (float) $latest->change_percent,
            'name' => null,
        ];
    }

    /**
     * Perform the Yahoo Finance HTTP request with rate-limit retry.
     *
     * @throws \RuntimeException
     */
    private function fetchFromYahoo(string $url, bool $isRetry = false): Response
    {
        $response = Http::timeout(config('stock_alert.fetch.yahoo_timeout'))
            ->get($url);

        if ($response->status() === 429 && ! $isRetry) {
            sleep(2);

            return $this->fetchFromYahoo($url, true);
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Failed to fetch stock price: HTTP {$response->status()}"
            );
        }

        return $response;
    }
}
