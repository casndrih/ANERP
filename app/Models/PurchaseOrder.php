<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PurchaseOrder extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['order_date'=>'date','expected_date'=>'date','approved_at'=>'datetime','subtotal'=>'decimal:2','tax_total'=>'decimal:2','total'=>'decimal:2']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function lines(): HasMany { return $this->hasMany(PurchaseOrderLine::class); }
}
