<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRefund extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['refund_date' => 'date', 'posted_at' => 'datetime', 'amount' => 'decimal:2']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id'); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
