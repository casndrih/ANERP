<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['journal_entries','supplier_bills','customer_invoices','bank_accounts','bank_reconciliations','bank_transactions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('branch_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
                $t->index(['company_id','branch_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['bank_transactions','bank_reconciliations','bank_accounts','customer_invoices','supplier_bills','journal_entries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['company_id','branch_id']);
                $t->dropConstrainedForeignId('branch_id');
            });
        }
    }
};
