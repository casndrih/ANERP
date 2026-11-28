<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_credit_note_id')->nullable()->constrained()->nullOnDelete();
            $table->string('return_number', 60);
            $table->date('return_date');
            $table->string('reason', 255);
            $table->string('gst_treatment', 20)->default('out_of_scope');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->string('currency', 3)->default('PGK');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'return_number'], 'customer_sales_return_number_unique');
            $table->index(['company_id', 'customer_invoice_id', 'status'], 'customer_sales_return_invoice_status_idx');
        });

        Schema::create('customer_sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_sales_return_id')->constrained('customer_sales_returns')->cascadeOnDelete();
            $table->foreignId('customer_invoice_line_id')->constrained('customer_invoice_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->string('condition', 20);
            $table->text('condition_notes')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->decimal('net_amount', 14, 2);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->timestamps();
            $table->unique(['customer_sales_return_id', 'customer_invoice_line_id'], 'customer_sales_return_invoice_line_unique');
        });

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->foreignId('source_sales_return_id')->nullable()->after('customer_invoice_id')
                ->constrained('customer_sales_returns')->nullOnDelete();
            $table->unique('source_sales_return_id', 'customer_credit_note_sales_return_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropUnique('customer_credit_note_sales_return_unique');
            $table->dropConstrainedForeignId('source_sales_return_id');
        });
        Schema::dropIfExists('customer_sales_return_lines');
        Schema::dropIfExists('customer_sales_returns');
    }
};
