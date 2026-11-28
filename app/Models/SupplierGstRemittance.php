<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierGstRemittance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'remittance_date' => 'date', 'posted_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function notice(): BelongsTo { return $this->belongsTo(SupplierGstWithholdingNotice::class, 'notice_id'); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function withholdings(): HasMany { return $this->hasMany(SupplierGstWithholding::class, 'remittance_id'); }
}
