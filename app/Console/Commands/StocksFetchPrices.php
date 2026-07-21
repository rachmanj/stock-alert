<?php

namespace App\Console\Commands;

use App\Models\PriceHistory;
use App\Services\AlertEvaluatorService;
use App\Services\NotificationService;
use App\Services\StockPriceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('stocks:fetch-prices')]
#[Description('Fetch latest prices for all active tracked stocks')]
class StocksFetchPrices extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StockPriceService $service): int
    {
        $this->info('Fetching stock prices...');

        $results = $service->fetchAllActiveTickers();

        $evaluator = app(AlertEvaluatorService::class);
        $notifier = app(NotificationService::class);

        $alertCount = 0;

        foreach ($results as $ticker => $data) {
            if (isset($data['error'])) {
                continue;
            }

            $previousPrice = PriceHistory::where('ticker', $ticker)
                ->where('recorded_at', '<', now())
                ->orderBy('recorded_at', 'desc')
                ->value('price');

            $triggered = $evaluator->evaluate($ticker, $data['price'], $previousPrice !== null ? (float) $previousPrice : null);

            foreach ($triggered as $alert) {
                $user = $alert->user;
                $notifier->sendAlert($user, $alert, $data['price']);
                $evaluator->markTriggered($alert, $data['price']);
                $alertCount++;
            }
        }

        $success = count(array_filter($results, fn ($r) => ! isset($r['error'])));
        $failed = count(array_filter($results, fn ($r) => isset($r['error'])));

        $this->info("Done: {$success} success, {$failed} failed");
        $this->info("Alerts triggered: {$alertCount}");

        foreach ($results as $ticker => $data) {
            if (isset($data['error'])) {
                $this->error("  {$ticker}: {$data['error']}");
            } else {
                $this->line('  '.$ticker.': Rp '.number_format($data['price'], 0, ',', '.'));
            }
        }

        return $success > 0 ? self::SUCCESS : self::FAILURE;
    }
}
