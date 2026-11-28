<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->string('session_number', 80);
            $table->string('status', 20)->default('open');
            $table->decimal('opening_float', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->foreignId('variance_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'session_number'], 'pos_session_company_number_unique');
            $table->index(['company_id', 'branch_id', 'status'], 'pos_session_scope_status_idx');
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $table->string('transaction_number', 80);
            $table->decimal('amount', 14, 2);
            $table->decimal('cash_tendered', 14, 2);
            $table->decimal('change_amount', 14, 2)->default(0);
            $table->string('payment_method', 20)->default('cash');
            $table->string('reference', 120)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'transaction_number'], 'pos_tx_company_number_unique');
            $table->unique('customer_invoice_id', 'pos_tx_invoice_unique');
            $table->index(['pos_session_id', 'created_at'], 'pos_tx_session_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transactions');
        Schema::dropIfExists('pos_sessions');
    }
};
