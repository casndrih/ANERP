<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockTransfer extends Model {
    protected $guarded = [];
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function fromWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class,'from_warehouse_id'); }
    public function toWarehouse(): BelongsTo { return $this->belongsTo(Warehouse::class,'to_warehouse_id'); }
}
