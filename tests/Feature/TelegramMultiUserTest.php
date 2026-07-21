<?php

namespace Tests\Feature;

use App\Models\PriceAlert;
use App\Models\PriceHistory;
use App\Models\TrackedStock;
use App\Models\User;
use App\Services\StockPriceService;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Mockery\MockInterface;
use Telegram\Bot\Api;
use Tests\TestCase;

class TelegramMultiUserTest extends TestCase
{
    use DatabaseTransactions;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $telegramMock = Mockery::mock('overload:'.Api::class);
        $telegramMock->shouldReceive('sendMessage')->andReturn([]);

        $this->userA = User::create([
            'telegram_id' => 111,
            'first_name' => 'Alice',
            'chat_id' => 111,
            'is_active' => true,
        ]);

        $this->userB = User::create([
            'telegram_id' => 222,
            'first_name' => 'Bob',
            'chat_id' => 222,
            'is_active' => true,
        ]);

        $this->mockStockPrice();
    }

    public function test_both_users_can_track_same_ticker_with_separate_rows(): void
    {
        $this->postWebhook(111, 'Alice', 111, '/track BBCA.JK')->assertOk();
        $this->postWebhook(222, 'Bob', 222, '/track BBCA.JK')->assertOk();

        $this->assertDatabaseCount('tracked_stocks', 2);
        $this->assertDatabaseHas('tracked_stocks', [
            'user_id' => $this->userA->id,
            'ticker' => 'BBCA.JK',
            'active' => true,
        ]);
        $this->assertDatabaseHas('tracked_stocks', [
            'user_id' => $this->userB->id,
            'ticker' => 'BBCA.JK',
            'active' => true,
        ]);
    }

    public function test_both_users_can_set_alerts_with_different_target_prices(): void
    {
        $this->seedTrackedStocks();

        $this->postWebhook(111, 'Alice', 111, '/alert BBCA.JK 8000 bawah')->assertOk();
        $this->postWebhook(222, 'Bob', 222, '/alert BBCA.JK 9000 bawah')->assertOk();

        $this->assertDatabaseCount('price_alerts', 2);
        $this->assertDatabaseHas('price_alerts', [
            'user_id' => $this->userA->id,
            'target_price' => '8000.00',
            'direction' => 'bawah',
        ]);
        $this->assertDatabaseHas('price_alerts', [
            'user_id' => $this->userB->id,
            'target_price' => '9000.00',
            'direction' => 'bawah',
        ]);
    }

    public function test_watchlist_only_shows_own_tickers(): void
    {
        TrackedStock::create([
            'user_id' => $this->userA->id,
            'ticker' => 'BBCA.JK',
            'name' => 'Bank Central Asia',
            'active' => true,
        ]);
        TrackedStock::create([
            'user_id' => $this->userB->id,
            'ticker' => 'TLKM.JK',
            'name' => 'Telkom Indonesia',
            'active' => true,
        ]);

        PriceHistory::create([
            'ticker' => 'BBCA.JK',
            'price' => 8250,
            'change' => 100,
            'change_percent' => 1.2,
            'recorded_at' => now(),
        ]);
        PriceHistory::create([
            'ticker' => 'TLKM.JK',
            'price' => 4100,
            'change' => -50,
            'change_percent' => -1.2,
            'recorded_at' => now(),
        ]);

        $service = app(TelegramBotService::class);

        $watchlistA = $service->handle($this->userA, '/watchlist');
        $watchlistB = $service->handle($this->userB, '/watchlist');

        $this->assertStringContainsString('BBCA.JK', $watchlistA);
        $this->assertStringNotContainsString('TLKM.JK', $watchlistA);

        $this->assertStringContainsString('TLKM.JK', $watchlistB);
        $this->assertStringNotContainsString('BBCA.JK', $watchlistB);
    }

    public function test_stats_reflect_per_user_counts(): void
    {
        TrackedStock::create([
            'user_id' => $this->userA->id,
            'ticker' => 'BBCA.JK',
            'name' => 'Bank Central Asia',
            'active' => true,
        ]);
        TrackedStock::create([
            'user_id' => $this->userA->id,
            'ticker' => 'TLKM.JK',
            'name' => 'Telkom Indonesia',
            'active' => true,
        ]);
        $stockB = TrackedStock::create([
            'user_id' => $this->userB->id,
            'ticker' => 'ASII.JK',
            'name' => 'Astra International',
            'active' => true,
        ]);

        PriceAlert::create([
            'user_id' => $this->userA->id,
            'tracked_stock_id' => TrackedStock::where('user_id', $this->userA->id)->first()->id,
            'ticker' => 'BBCA.JK',
            'target_price' => 8000,
            'direction' => 'bawah',
            'is_triggered' => false,
        ]);
        PriceAlert::create([
            'user_id' => $this->userB->id,
            'tracked_stock_id' => $stockB->id,
            'ticker' => 'ASII.JK',
            'target_price' => 5000,
            'direction' => 'atas',
            'is_triggered' => false,
        ]);
        PriceAlert::create([
            'user_id' => $this->userB->id,
            'tracked_stock_id' => $stockB->id,
            'ticker' => 'ASII.JK',
            'target_price' => 4500,
            'direction' => 'bawah',
            'is_triggered' => false,
        ]);

        $service = app(TelegramBotService::class);

        $statsA = $service->handle($this->userA, '/stats');
        $statsB = $service->handle($this->userB, '/stats');

        $this->assertStringContainsString('2 track, 1 alert', $statsA);
        $this->assertStringContainsString('1 track, 2 alert', $statsB);
    }

    public function test_user_cannot_cancel_another_users_alert(): void
    {
        $stockB = TrackedStock::create([
            'user_id' => $this->userB->id,
            'ticker' => 'BBCA.JK',
            'name' => 'Bank Central Asia',
            'active' => true,
        ]);

        $bobAlert = PriceAlert::create([
            'user_id' => $this->userB->id,
            'tracked_stock_id' => $stockB->id,
            'ticker' => 'BBCA.JK',
            'target_price' => 9000,
            'direction' => 'bawah',
            'is_triggered' => false,
        ]);

        $this->postWebhook(111, 'Alice', 111, '/cancela '.$bobAlert->id)->assertOk();

        $this->assertDatabaseHas('price_alerts', ['id' => $bobAlert->id]);
    }

    public function test_rate_limit_throttles_after_threshold(): void
    {
        $limit = config('stock_alert.limits.telegram_rate_per_min', 30);
        $rateLimitKey = md5('telegram111');

        for ($i = 0; $i < $limit; $i++) {
            $this->postWebhook(111, 'Alice', 111, '/help')
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', (string) ($limit - $i - 1));
        }

        $this->assertTrue(
            RateLimiter::tooManyAttempts($rateLimitKey, $limit),
            'Rate limiter should block after '.$limit.' requests/min'
        );

        $this->postWebhook(111, 'Alice', 111, '/help')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    private function postWebhook(int $telegramId, string $firstName, int $chatId, string $text)
    {
        return $this->postJson('/telegram/webhook', [
            'message' => [
                'from' => ['id' => $telegramId, 'first_name' => $firstName],
                'chat' => ['id' => $chatId],
                'text' => $text,
            ],
        ]);
    }

    private function seedTrackedStocks(): void
    {
        TrackedStock::create([
            'user_id' => $this->userA->id,
            'ticker' => 'BBCA.JK',
            'name' => 'Bank Central Asia',
            'active' => true,
        ]);
        TrackedStock::create([
            'user_id' => $this->userB->id,
            'ticker' => 'BBCA.JK',
            'name' => 'Bank Central Asia',
            'active' => true,
        ]);
    }

    private function mockStockPrice(): void
    {
        $this->mock(StockPriceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchPrice')
                ->andReturn([
                    'price' => 8250.0,
                    'change' => 250.0,
                    'change_percent' => 3.13,
                    'name' => 'Bank Central Asia',
                ]);
        });
    }
}
