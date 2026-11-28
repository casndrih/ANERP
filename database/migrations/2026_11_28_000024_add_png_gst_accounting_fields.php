<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('gst_registered')->default(false);
            $table->string('gst_registration_number', 80)->nullable();
        });
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->decimal('net_amount', 14, 2)->nullable();
            $table->string('gst_treatment', 20)->default('out_of_scope');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->boolean('supplier_gst_registered')->default(false);
            $table->string('tax_invoice_reference', 120)->nullable();
            $table->boolean('input_tax_credit_eligible')->default(false);
        });
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->decimal('net_amount', 14, 2)->nullable();
            $table->string('gst_treatment', 20)->default('out_of_scope');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn(['net_amount', 'gst_treatment', 'gst_rate', 'gst_amount']);
        });
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn(['net_amount', 'gst_treatment', 'gst_rate', 'gst_amount', 'supplier_gst_registered', 'tax_invoice_reference', 'input_tax_credit_eligible']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['gst_registered', 'gst_registration_number']);
        });
    }
};
