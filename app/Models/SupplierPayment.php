<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'posted_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function allocations(): HasMany { return $this->hasMany(SupplierPaymentAllocation::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
