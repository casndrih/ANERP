<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReceiptAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array { return ['amount' => 'decimal:2']; }

    public function receipt(): BelongsTo { return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id'); }
}
