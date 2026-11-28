<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'variance_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function stockCount(): BelongsTo { return $this->belongsTo(StockCount::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
