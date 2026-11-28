<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerSalesReturn extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'posted_at' => 'datetime',
            'net_amount' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function creditNote(): BelongsTo { return $this->belongsTo(CustomerCreditNote::class, 'customer_credit_note_id'); }
    public function lines(): HasMany { return $this->hasMany(CustomerSalesReturnLine::class); }
}
