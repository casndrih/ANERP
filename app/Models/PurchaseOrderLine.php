<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PurchaseOrderLine extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['quantity_ordered'=>'decimal:3','quantity_received'=>'decimal:3','unit_cost'=>'decimal:2','line_total'=>'decimal:2']; }
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class,'purchase_order_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
