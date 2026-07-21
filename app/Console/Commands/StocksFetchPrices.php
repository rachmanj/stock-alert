<?php

namespace App\Console\Commands;

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

        $success = count(array_filter($results, fn ($r) => ! isset($r['error'])));
        $failed = count(array_filter($results, fn ($r) => isset($r['error'])));

        $this->info("Done: {$success} success, {$failed} failed");

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
