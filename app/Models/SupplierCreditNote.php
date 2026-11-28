<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierCreditNote extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['credit_note_date' => 'date', 'posted_at' => 'datetime', 'amount' => 'decimal:2']; }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function bill(): BelongsTo { return $this->belongsTo(SupplierBill::class, 'supplier_bill_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
