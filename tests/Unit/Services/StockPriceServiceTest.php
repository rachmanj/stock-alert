<?php

namespace Tests\Unit\Services;

use App\Services\StockPriceService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StockPriceServiceTest extends TestCase
{
    private StockPriceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'stock_alert',
        ]);

        $this->service = new StockPriceService;
    }

    public function test_fetch_price_parses_yahoo_response_correctly(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 8250.0,
                                'previousClose' => 8000.0,
                                'shortName' => 'Bank Central Asia',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = $this->service->fetchPrice('BBCA.JK');

        $this->assertSame(8250.0, $result['price']);
        $this->assertSame(250.0, $result['change']);
        $this->assertSame(3.13, $result['change_percent']);
        $this->assertSame('Bank Central Asia', $result['name']);
    }

    public function test_fetch_price_returns_error_for_unknown_ticker(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response([
                'chart' => [
                    'result' => [],
                ],
            ]),
        ]);

        $result = $this->service->fetchPrice('INVALID.JK');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('INVALID.JK', $result['error']);
    }

    public function test_fetch_price_throws_on_http_failure(): void
    {
        Http::fake([
            'query1.finance.yahoo.com/*' => Http::response('Server Error', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch stock price: HTTP 500');

        $this->service->fetchPrice('BBCA.JK');
    }

    public function test_fetch_price_throws_on_connection_timeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out after 10000ms');
        });

        $this->expectException(ConnectionException::class);

        $this->service->fetchPrice('BBCA.JK');
    }
}
