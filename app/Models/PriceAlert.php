<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceAlert extends Model
{
    protected $fillable = [
        'user_id',
        'tracked_stock_id',
        'ticker',
        'target_price',
        'direction',
        'is_triggered',
        'triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'target_price' => 'decimal:2',
            'is_triggered' => 'boolean',
            'triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trackedStock(): BelongsTo
    {
        return $this->belongsTo(TrackedStock::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
