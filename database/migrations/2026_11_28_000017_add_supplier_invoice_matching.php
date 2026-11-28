<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('invoice_type', 24)->default('general')->after('description');
            $table->string('match_status', 24)->default('not_required')->after('invoice_type');
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->index(['company_id', 'match_status', 'bill_date'], 'supplier_bill_match_scope_idx');
        });

        Schema::create('supplier_bill_match_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->decimal('quantity_billed', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
            $table->unique(['supplier_bill_id', 'purchase_order_line_id'], 'supplier_bill_po_line_unique');
        });

        Schema::create('landed_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->decimal('allocated_amount', 14, 2);
            $table->decimal('inventory_amount', 14, 2);
            $table->decimal('cogs_amount', 14, 2);
            $table->timestamps();
            $table->unique(['supplier_bill_id', 'purchase_order_line_id'], 'landed_cost_bill_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
        Schema::dropIfExists('supplier_bill_match_lines');
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropIndex('supplier_bill_match_scope_idx');
            $table->dropConstrainedForeignId('matched_by');
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn(['invoice_type', 'match_status', 'matched_at']);
        });
    }
};
