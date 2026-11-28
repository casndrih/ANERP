<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_stock_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('credit_note_number', 80);
            $table->date('return_date');
            $table->string('description', 255);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->string('currency', 3)->default('PGK');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'credit_note_number'], 'supplier_stock_return_number_unique');
            $table->index(['company_id', 'supplier_bill_id', 'status'], 'supplier_stock_return_bill_status_idx');
        });

        Schema::create('supplier_stock_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_stock_return_id')->constrained('supplier_stock_returns')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->timestamps();
            $table->unique(['supplier_stock_return_id', 'purchase_order_line_id'], 'supplier_stock_return_po_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_stock_return_lines');
        Schema::dropIfExists('supplier_stock_returns');
    }
};
