<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = [
        'telegram_id',
        'telegram_username',
        'first_name',
        'chat_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'chat_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function trackedStocks(): HasMany
    {
        return $this->hasMany(TrackedStock::class);
    }

    public function priceAlerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
