<?php

namespace Tests\Unit\Services;

use App\Models\PriceAlert;
use App\Models\TrackedStock;
use App\Models\User;
use App\Services\StockPriceService;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class TelegramBotServiceTest extends TestCase
{
    use DatabaseTransactions;

    private TelegramBotService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'telegram_id' => 123456789,
            'telegram_username' => 'testuser',
            'first_name' => 'Iwan',
            'chat_id' => 123456789,
            'is_active' => true,
        ]);

        $this->service = app(TelegramBotService::class);
    }

    public function test_start_handler_returns_welcome_message(): void
    {
        $response = $this->service->handle($this->user, '/start');

        $this->assertStringContainsString('Halo Iwan', $response);
        $this->assertStringContainsString('/track BBCA.JK', $response);
        $this->assertStringContainsString('/help', $response);
    }

    public function test_track_handler_adds_ticker_and_fetches_price(): void
    {
        $this->mock(StockPriceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchPrice')
                ->once()
                ->with('BBCA.JK')
                ->andReturn([
                    'price' => 8250.0,
                    'change' => 250.0,
                    'change_percent' => 3.13,
                    'name' => 'Bank Central Asia',
                ]);
        });

        $service = app(TelegramBotService::class);
        $response = $service->handle($this->user, '/track BBCA.JK');

        $this->assertStringContainsString('BBCA.JK', $response);
        $this->assertStringContainsString('Bank Central Asia', $response);
        $this->assertStringContainsString('Rp 8.250', $response);

        $this->assertDatabaseHas('tracked_stocks', [
            'user_id' => $this->user->id,
            'ticker' => 'BBCA.JK',
            'active' => true,
        ]);
    }

    public function test_track_handler_rejects_duplicate_active_ticker(): void
    {
        TrackedStock::create([
            'user_id' => $this->user->id,
            'ticker' => 'BBCA.JK',
            'name' => 'Bank Central Asia',
            'active' => true,
        ]);

        $response = $this->service->handle($this->user, '/track BBCA.JK');

        $this->assertSame('Kamu sudah track ticker ini.', $response);
    }

    public function test_alert_handler_creates_alert_for_tracked_ticker(): void
    {
        $stock = TrackedStock::create([
            'user_id' => $this->user->id,
            'ticker' => 'BBCA.JK',
            'name' => 'Bank Central Asia',
            'active' => true,
        ]);

        $response = $this->service->handle($this->user, '/alert BBCA.JK 8000 bawah');

        $this->assertStringContainsString('Alert diset', $response);
        $this->assertStringContainsString('BBCA.JK', $response);
        $this->assertStringContainsString('bawah', $response);
        $this->assertStringContainsString('Rp 8.000', $response);

        $this->assertDatabaseHas('price_alerts', [
            'user_id' => $this->user->id,
            'tracked_stock_id' => $stock->id,
            'ticker' => 'BBCA.JK',
            'target_price' => '8000.00',
            'direction' => 'bawah',
            'is_triggered' => false,
        ]);
    }

    public function test_alert_handler_rejects_untracked_ticker(): void
    {
        $response = $this->service->handle($this->user, '/alert BBCA.JK 8000 bawah');

        $this->assertStringContainsString('belum track', $response);
        $this->assertDatabaseCount('price_alerts', 0);
    }

    public function test_cancel_alert_only_affects_own_alerts(): void
    {
        $otherUser = User::create([
            'telegram_id' => 987654321,
            'chat_id' => 987654321,
            'is_active' => true,
        ]);

        $stock = TrackedStock::create([
            'user_id' => $otherUser->id,
            'ticker' => 'TLKM.JK',
            'name' => 'Telkom',
            'active' => true,
        ]);

        $otherAlert = PriceAlert::create([
            'user_id' => $otherUser->id,
            'tracked_stock_id' => $stock->id,
            'ticker' => 'TLKM.JK',
            'target_price' => 4000,
            'direction' => 'atas',
            'is_triggered' => false,
        ]);

        $response = $this->service->handle($this->user, '/cancela '.$otherAlert->id);

        $this->assertStringContainsString('tidak ditemukan', $response);
        $this->assertDatabaseHas('price_alerts', ['id' => $otherAlert->id]);
    }
}
