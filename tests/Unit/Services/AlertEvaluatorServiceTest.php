<?php

namespace Tests\Unit\Services;

use App\Models\PriceAlert;
use App\Models\TrackedStock;
use App\Models\User;
use App\Services\AlertEvaluatorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AlertEvaluatorServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AlertEvaluatorService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AlertEvaluatorService;

        $this->user = User::create([
            'telegram_id' => 111222333,
            'chat_id' => 111222333,
            'is_active' => true,
        ]);
    }

    public function test_bawah_triggers_when_price_crosses_below_target(): void
    {
        $alert = $this->createAlert('BBCA.JK', 8000, 'bawah');

        $triggered = $this->service->evaluate('BBCA.JK', 7980, 8500);

        $this->assertCount(1, $triggered);
        $this->assertSame($alert->id, $triggered[0]->id);
    }

    public function test_bawah_does_not_trigger_when_price_has_not_crossed(): void
    {
        $this->createAlert('BBCA.JK', 8000, 'bawah');

        $triggered = $this->service->evaluate('BBCA.JK', 8100, 8200);

        $this->assertCount(0, $triggered);
    }

    public function test_bawah_does_not_trigger_when_already_below_target(): void
    {
        $this->createAlert('BBCA.JK', 8000, 'bawah');

        $triggered = $this->service->evaluate('BBCA.JK', 7850, 7900);

        $this->assertCount(0, $triggered);
    }

    public function test_atas_triggers_when_price_crosses_above_target(): void
    {
        $alert = $this->createAlert('AAPL', 300, 'atas');

        $triggered = $this->service->evaluate('AAPL', 305, 280);

        $this->assertCount(1, $triggered);
        $this->assertSame($alert->id, $triggered[0]->id);
    }

    public function test_atas_does_not_trigger_when_price_has_not_crossed(): void
    {
        $this->createAlert('AAPL', 300, 'atas');

        $triggered = $this->service->evaluate('AAPL', 295, 290);

        $this->assertCount(0, $triggered);
    }

    public function test_atas_does_not_trigger_when_already_above_target(): void
    {
        $this->createAlert('AAPL', 300, 'atas');

        $triggered = $this->service->evaluate('AAPL', 315, 310);

        $this->assertCount(0, $triggered);
    }

    public function test_does_not_trigger_when_previous_price_is_null(): void
    {
        $this->createAlert('BBCA.JK', 8000, 'bawah');

        $triggered = $this->service->evaluate('BBCA.JK', 7980, null);

        $this->assertCount(0, $triggered);
    }

    private function createAlert(string $ticker, float $targetPrice, string $direction): PriceAlert
    {
        $stock = TrackedStock::create([
            'user_id' => $this->user->id,
            'ticker' => $ticker,
            'active' => true,
        ]);

        return PriceAlert::create([
            'user_id' => $this->user->id,
            'tracked_stock_id' => $stock->id,
            'ticker' => $ticker,
            'target_price' => $targetPrice,
            'direction' => $direction,
            'is_triggered' => false,
        ]);
    }
}
