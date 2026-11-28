<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CustomerInvoice extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['invoice_date'=>'date','due_date'=>'date','posted_at'=>'datetime','amount'=>'decimal:2']; }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}