<?php

namespace App\Services;

use App\Models\PriceAlert;

class AlertEvaluatorService
{
    /**
     * Evaluasi semua alert aktif untuk ticker tertentu.
     * Return array of triggered PriceAlert models.
     *
     * @return array<int, PriceAlert>
     */
    public function evaluate(string $ticker, float $currentPrice, ?float $previousPrice): array
    {
        $alerts = PriceAlert::where('ticker', $ticker)
            ->where('is_triggered', false)
            ->get();

        $triggered = [];

        foreach ($alerts as $alert) {
            if ($alert->direction === 'bawah') {
                if ($previousPrice !== null && $previousPrice > $alert->target_price
                    && $currentPrice <= $alert->target_price) {
                    $triggered[] = $alert;
                }
            } elseif ($alert->direction === 'atas') {
                if ($previousPrice !== null && $previousPrice < $alert->target_price
                    && $currentPrice >= $alert->target_price) {
                    $triggered[] = $alert;
                }
            }
        }

        return $triggered;
    }

    public function markTriggered(PriceAlert $alert, float $currentPrice): void
    {
        $alert->update([
            'is_triggered' => true,
            'triggered_at' => now(),
        ]);
    }
}
