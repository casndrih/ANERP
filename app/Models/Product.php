<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Product extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['track_stock'=>'boolean','is_active'=>'boolean','standard_cost'=>'decimal:2','selling_price'=>'decimal:2']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
