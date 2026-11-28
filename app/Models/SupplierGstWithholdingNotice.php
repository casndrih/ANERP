<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierGstWithholdingNotice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['notice_date' => 'date', 'effective_from' => 'date', 'effective_to' => 'date', 'activated_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'supplier_gst_notice_suppliers', 'notice_id', 'supplier_id')
            ->withPivot(['effective_from', 'effective_to', 'supplier_tin_snapshot'])->withTimestamps();
    }

    public function withholdings(): HasMany { return $this->hasMany(SupplierGstWithholding::class, 'notice_id'); }
    public function remittances(): HasMany { return $this->hasMany(SupplierGstRemittance::class, 'notice_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
