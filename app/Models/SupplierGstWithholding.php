<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierGstWithholding extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'payment_date' => 'date', 'remitted_at' => 'datetime', 'supplier_advised_at' => 'datetime',
            'gross_settlement' => 'decimal:2', 'gst_withheld' => 'decimal:2', 'cash_paid' => 'decimal:2'];
    }

    public function notice(): BelongsTo { return $this->belongsTo(SupplierGstWithholdingNotice::class, 'notice_id'); }
    public function payment(): BelongsTo { return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id'); }
    public function allocation(): BelongsTo { return $this->belongsTo(SupplierPaymentAllocation::class, 'supplier_payment_allocation_id'); }
    public function bill(): BelongsTo { return $this->belongsTo(SupplierBill::class, 'supplier_bill_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function remittance(): BelongsTo { return $this->belongsTo(SupplierGstRemittance::class, 'remittance_id'); }
}
