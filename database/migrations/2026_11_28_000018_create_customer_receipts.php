<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number', 80);
            $table->date('receipt_date');
            $table->string('reference', 120)->nullable();
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('PGK');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'receipt_number'], 'customer_receipt_number_unique');
            $table->index(['company_id', 'customer_id', 'receipt_date'], 'customer_receipt_customer_date_idx');
        });

        Schema::create('customer_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_receipt_id')->constrained('customer_receipts')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['customer_receipt_id', 'customer_invoice_id'], 'customer_receipt_invoice_unique');
            $table->index('customer_invoice_id', 'customer_receipt_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipt_allocations');
        Schema::dropIfExists('customer_receipts');
    }
};
