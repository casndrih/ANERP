<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->decimal('deposit_amount', 14, 2)->default(0)->after('amount');
        });

        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropUnique('pos_tx_invoice_unique');
            $table->index(['customer_invoice_id', 'status'], 'pos_tx_invoice_status_idx');
            $table->string('status', 20)->default('posted')->after('payment_method');
            $table->timestamp('voided_at')->nullable()->after('created_by');
        });

        Schema::create('pos_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->foreignId('original_pos_transaction_id')->constrained('pos_transactions')->restrictOnDelete();
            $table->foreignId('customer_credit_note_id')->constrained('customer_credit_notes')->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255);
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'customer_credit_note_id', 'status'], 'pos_refund_credit_status_idx');
            $table->index(['pos_session_id', 'status'], 'pos_refund_session_status_idx');
        });

        Schema::create('pos_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->restrictOnDelete();
            $table->foreignId('pos_transaction_id')->unique()->constrained('pos_transactions')->restrictOnDelete();
            $table->foreignId('customer_receipt_id')->constrained('customer_receipts')->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255);
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'customer_invoice_id', 'status'], 'pos_void_invoice_status_idx');
            $table->index(['pos_session_id', 'status'], 'pos_void_session_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_voids');
        Schema::dropIfExists('pos_refunds');
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropIndex('pos_tx_invoice_status_idx');
            $table->unique('customer_invoice_id', 'pos_tx_invoice_unique');
            $table->dropColumn(['status', 'voided_at']);
        });
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropColumn('deposit_amount');
        });
    }
};
