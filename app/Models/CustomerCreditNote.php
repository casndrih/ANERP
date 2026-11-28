<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditNote extends Model
{
    protected $guarded = [];
    protected function casts(): array
    {
        return ['credit_note_date' => 'date', 'posted_at' => 'datetime', 'amount' => 'decimal:2', 'deposit_amount' => 'decimal:2'];
    }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(CustomerInvoice::class, 'customer_invoice_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function sourceSalesReturn(): BelongsTo { return $this->belongsTo(CustomerSalesReturn::class, 'source_sales_return_id'); }
}
