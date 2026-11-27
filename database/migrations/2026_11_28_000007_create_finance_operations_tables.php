<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $t) {
            $t->string('normal_balance')->default('debit');
            $t->text('description')->nullable();
        });
        Schema::table('journal_entries', function (Blueprint $t) {
            $t->foreignId('created_by')->nullable()->after('description')->constrained('users')->nullOnDelete();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code'); $t->string('name'); $t->string('tin')->nullable();
            $t->string('email')->nullable(); $t->string('phone')->nullable();
            $t->text('billing_address')->nullable(); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->unique(['company_id','code']);
        });
        Schema::create('supplier_bills', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $t->string('bill_number'); $t->date('bill_date'); $t->date('due_date')->nullable();
            $t->decimal('amount',14,2); $t->string('currency',3)->default('PGK');
            $t->string('description'); $t->string('status')->default('draft');
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable(); $t->timestamps();
            $t->unique(['company_id','bill_number']);
        });
        Schema::create('customer_invoices', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('revenue_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $t->string('invoice_number'); $t->date('invoice_date'); $t->date('due_date')->nullable();
            $t->decimal('amount',14,2); $t->string('currency',3)->default('PGK');
            $t->string('description'); $t->string('status')->default('draft');
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable(); $t->timestamps();
            $t->unique(['company_id','invoice_number']);
        });
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('code'); $t->string('name'); $t->string('bank_name')->nullable();
            $t->string('account_number')->nullable(); $t->string('currency',3)->default('PGK');
            $t->boolean('is_active')->default(true); $t->timestamps();
            $t->unique(['company_id','code']);
        });
        Schema::create('bank_reconciliations', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $t->date('statement_start'); $t->date('statement_end');
            $t->decimal('opening_balance',14,2); $t->decimal('closing_balance',14,2);
            $t->decimal('reconciled_balance',14,2)->default(0);
            $t->string('status')->default('completed');
            $t->foreignId('reconciled_by')->constrained('users')->cascadeOnDelete();
            $t->timestamp('reconciled_at')->nullable(); $t->timestamps();
        });
        Schema::create('bank_transactions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->nullOnDelete();
            $t->date('transaction_date'); $t->string('reference')->nullable();
            $t->string('description'); $t->string('direction'); $t->decimal('amount',14,2);
            $t->boolean('is_reconciled')->default(false);
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->timestamps(); $t->index(['company_id','bank_account_id','is_reconciled','transaction_date']);
        });
    }
    public function down(): void
    {
        foreach (['bank_transactions','bank_reconciliations','bank_accounts','customer_invoices','supplier_bills','customers'] as $table) Schema::dropIfExists($table);
        Schema::table('journal_entries', function (Blueprint $t) { $t->dropConstrainedForeignId('created_by'); });
        Schema::table('accounts', function (Blueprint $t) { $t->dropColumn(['normal_balance','description']); });
    }
};