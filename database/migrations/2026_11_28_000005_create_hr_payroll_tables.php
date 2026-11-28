<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('department')->nullable();
            $t->string('job_title')->nullable();
            $t->string('work_email')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('bank_account_name')->nullable();
            $t->string('bank_account_number')->nullable();
            $t->decimal('base_salary', 14, 2)->default(0);
            $t->string('pay_frequency')->default('monthly');
            $t->string('currency', 3)->default('PGK');
        });
        Schema::create('leave_types', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code'); $t->string('name'); $t->decimal('annual_entitlement', 8, 2)->default(0);
            $t->boolean('requires_approval')->default(true); $t->boolean('is_paid')->default(true);
            $t->boolean('is_active')->default(true); $t->timestamps();
            $t->unique(['company_id','code']);
        });
        Schema::create('leave_applications', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $t->date('start_date'); $t->date('end_date'); $t->decimal('days',8,2);
            $t->text('reason')->nullable(); $t->string('status')->default('pending');
            $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable(); $t->text('decision_note')->nullable();
            $t->timestamps(); $t->index(['company_id','status','start_date']);
        });
        Schema::create('attendance_entries', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('work_date'); $t->time('clock_in')->nullable(); $t->time('clock_out')->nullable();
            $t->decimal('hours',8,2)->default(0); $t->text('notes')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $t->timestamps(); $t->unique(['employee_id','work_date']);
        });
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('run_number'); $t->date('period_start'); $t->date('period_end');
            $t->date('payment_date')->nullable(); $t->string('status')->default('draft');
            $t->string('currency',3)->default('PGK'); $t->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable(); $t->timestamps();
            $t->unique(['company_id','run_number']);
        });
        Schema::create('payroll_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->decimal('base_pay',14,2)->default(0); $t->decimal('allowances',14,2)->default(0);
            $t->decimal('gross_pay',14,2)->default(0); $t->decimal('tax_deduction',14,2)->default(0);
            $t->decimal('other_deductions',14,2)->default(0); $t->decimal('employer_super',14,2)->default(0);
            $t->decimal('net_pay',14,2)->default(0); $t->text('calculation_note')->nullable();
            $t->timestamps(); $t->unique(['payroll_run_id','employee_id']);
        });
        Schema::create('payroll_rules', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('rule_code'); $t->string('name'); $t->string('rule_type');
            $t->decimal('rate',12,6)->nullable(); $t->decimal('fixed_amount',14,2)->nullable();
            $t->date('effective_from'); $t->date('effective_to')->nullable();
            $t->string('source_reference')->nullable(); $t->boolean('is_verified')->default(false);
            $t->boolean('is_active')->default(true); $t->timestamps();
            $t->index(['company_id','rule_code','effective_from']);
        });
    }
    public function down(): void
    {
        foreach (['payroll_rules','payroll_lines','payroll_runs','attendance_entries','leave_applications','leave_types'] as $table) Schema::dropIfExists($table);
        Schema::table('employees', function (Blueprint $t) {
            $t->dropColumn(['department','job_title','work_email','bank_name','bank_account_name','bank_account_number','base_salary','pay_frequency','currency']);
        });
    }
};