<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_number', 80);
            $table->date('payment_date');
            $table->string('reference', 120)->nullable();
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('PGK');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'payment_number'], 'supplier_payment_number_unique');
            $table->index(['company_id', 'supplier_id', 'payment_date'], 'supplier_payment_supplier_date_idx');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['supplier_payment_id', 'supplier_bill_id'], 'supplier_payment_bill_unique');
            $table->index('supplier_bill_id', 'supplier_payment_bill_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};
