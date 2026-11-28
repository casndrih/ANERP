<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'net_amount' => 'decimal:2', 'gst_amount' => 'decimal:2', 'total_amount' => 'decimal:2']; }
    public function order(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
