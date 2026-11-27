<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $t) {
            $t->string('tax_residency')->default('resident');
            $t->boolean('employment_declaration_received')->default(false);
            $t->decimal('annual_dependant_tax_credit', 10, 2)->default(0);
            $t->boolean('superannuation_opt_in')->default(false);
            $t->boolean('superannuation_exempt')->default(false);
            $t->string('superannuation_exempt_reason')->nullable();
            $t->string('superannuation_fund')->nullable();
            $t->string('superannuation_member_number')->nullable();
        });
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->string('pay_frequency')->default('monthly');
            $t->string('statutory_rule_version')->default('PNG-ITA-2025-2026.1');
        });
        Schema::table('payroll_lines', function (Blueprint $t) {
            $t->decimal('taxable_gross', 14, 2)->default(0);
            $t->decimal('employee_super', 14, 2)->default(0);
            $t->string('tax_rule_version')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $t) {
            $t->dropColumn(['taxable_gross','employee_super','tax_rule_version']);
        });
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->dropColumn(['pay_frequency','statutory_rule_version']);
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->dropColumn(['tax_residency','employment_declaration_received','annual_dependant_tax_credit','superannuation_opt_in','superannuation_exempt','superannuation_exempt_reason','superannuation_fund','superannuation_member_number']);
        });
    }
};