<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime', 'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime', 'net_amount' => 'decimal:2', 'gst_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function lines(): HasMany { return $this->hasMany(SalesOrderLine::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id'); }
}
