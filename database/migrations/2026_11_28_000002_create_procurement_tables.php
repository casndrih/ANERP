<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('order_number');
            $t->date('order_date');
            $t->date('expected_date')->nullable();
            $t->string('status')->default('draft');
            $t->string('currency', 3)->default('PGK');
            $t->text('notes')->nullable();
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('tax_total', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'order_number']);
            $t->index(['company_id', 'status', 'order_date']);
        });
        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->decimal('quantity_ordered', 14, 3);
            $t->decimal('quantity_received', 14, 3)->default(0);
            $t->decimal('unit_cost', 14, 2);
            $t->decimal('line_total', 14, 2);
            $t->text('description')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
