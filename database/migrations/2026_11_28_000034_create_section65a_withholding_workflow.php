<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->string('supply_province', 100)->nullable()->after('supplier_gst_registration_number');
        });

        Schema::create('supplier_gst_withholding_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('notice_number', 100);
            $table->string('issuing_authority', 120)->default('Internal Revenue Commission');
            $table->date('notice_date');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('supplier_scope', 24)->default('all_suppliers');
            $table->string('remittance_form_code', 3);
            $table->string('document_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'notice_number'], 'supplier_s65a_notice_number_unique');
            $table->index(['company_id', 'status', 'effective_from', 'effective_to'], 'supplier_s65a_notice_effective_idx');
        });

        Schema::create('supplier_gst_notice_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('supplier_gst_withholding_notices')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('supplier_tin_snapshot', 80)->nullable();
            $table->timestamps();
            $table->unique(['notice_id', 'supplier_id'], 'supplier_s65a_notice_supplier_unique');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('cash_amount', 14, 2)->default(0)->after('amount');
            $table->decimal('withheld_amount', 14, 2)->default(0)->after('cash_amount');
        });
        DB::table('supplier_payments')->update(['cash_amount' => DB::raw('amount')]);

        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->foreignId('section65a_notice_id')->nullable()->after('supplier_bill_id')
                ->constrained('supplier_gst_withholding_notices')->nullOnDelete();
            $table->decimal('cash_amount', 14, 2)->default(0)->after('amount');
            $table->decimal('withheld_amount', 14, 2)->default(0)->after('cash_amount');
        });
        DB::table('supplier_payment_allocations')->update([
            'cash_amount' => DB::raw('amount'),
            'withheld_amount' => 0,
        ]);

        Schema::create('supplier_gst_remittances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notice_id')->constrained('supplier_gst_withholding_notices')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->string('remittance_number', 80);
            $table->string('form_code', 3);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date')->nullable();
            $table->date('remittance_date');
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('PGK');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'remittance_number'], 'supplier_s65a_remittance_number_unique');
            $table->index(['company_id', 'period_start', 'period_end', 'status'], 'supplier_s65a_remittance_period_idx');
        });

        Schema::create('supplier_gst_withholdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notice_id')->constrained('supplier_gst_withholding_notices')->restrictOnDelete();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->restrictOnDelete();
            $table->foreignId('supplier_payment_allocation_id')->unique()->constrained('supplier_payment_allocations')->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('remittance_id')->nullable()->constrained('supplier_gst_remittances')->nullOnDelete();
            $table->string('supplier_name_snapshot', 255);
            $table->string('supplier_tin_snapshot', 80)->nullable();
            $table->string('supplier_province', 100)->nullable();
            $table->string('bill_number_snapshot', 100);
            $table->date('bill_date');
            $table->date('payment_date');
            $table->decimal('gross_settlement', 14, 2);
            $table->decimal('gst_withheld', 14, 2);
            $table->decimal('cash_paid', 14, 2);
            $table->string('status', 20)->default('unremitted');
            $table->timestamp('remitted_at')->nullable();
            $table->timestamp('supplier_advised_at')->nullable();
            $table->string('supplier_advice_reference', 120)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'notice_id', 'status', 'payment_date'], 'supplier_s65a_withholding_period_idx');
            $table->index(['supplier_id', 'payment_date'], 'supplier_s65a_withholding_supplier_idx');
        });

        Schema::create('supplier_gst_remittance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_id')->constrained('supplier_gst_remittances')->cascadeOnDelete();
            $table->foreignId('withholding_id')->constrained('supplier_gst_withholdings')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['remittance_id', 'withholding_id'], 'supplier_s65a_remittance_withholding_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_gst_remittance_lines');
        Schema::dropIfExists('supplier_gst_withholdings');
        Schema::dropIfExists('supplier_gst_remittances');
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section65a_notice_id');
            $table->dropColumn(['cash_amount', 'withheld_amount']);
        });
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn(['cash_amount', 'withheld_amount']);
        });
        Schema::dropIfExists('supplier_gst_notice_suppliers');
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn('supply_province');
        });
        Schema::dropIfExists('supplier_gst_withholding_notices');
    }
};
