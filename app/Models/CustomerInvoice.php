<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CustomerInvoice extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['invoice_date'=>'date','due_date'=>'date','posted_at'=>'datetime','amount'=>'decimal:2']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function lines(): HasMany { return $this->hasMany(CustomerInvoiceLine::class); }
}