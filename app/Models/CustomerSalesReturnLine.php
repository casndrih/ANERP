<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSalesReturnLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function salesReturn(): BelongsTo { return $this->belongsTo(CustomerSalesReturn::class, 'customer_sales_return_id'); }
    public function invoiceLine(): BelongsTo { return $this->belongsTo(CustomerInvoiceLine::class, 'customer_invoice_line_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function originalIssueMovement(): BelongsTo { return $this->belongsTo(StockMovement::class, 'original_issue_movement_id'); }
    public function stockMovement(): BelongsTo { return $this->belongsTo(StockMovement::class); }
}
