<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    protected $table = 'price_history';

    protected $fillable = [
        'ticker',
        'price',
        'change',
        'change_percent',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'change' => 'decimal:2',
            'change_percent' => 'decimal:4',
            'recorded_at' => 'datetime',
        ];
    }
}
