<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class SupplierBill extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['bill_date'=>'date','due_date'=>'date','posted_at'=>'datetime','amount'=>'decimal:2']; }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
}