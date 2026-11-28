<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BankTransaction extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['transaction_date'=>'date','is_reconciled'=>'boolean','amount'=>'decimal:2']; }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
}