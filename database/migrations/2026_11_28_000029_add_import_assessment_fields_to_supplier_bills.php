<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->string('gst_source', 20)->default('domestic');
            $table->string('customs_assessment_reference', 120)->nullable();
            $table->string('import_declaration_number', 120)->nullable();
            $table->string('hs_code', 30)->nullable();
            $table->decimal('verified_duty_rate', 7, 3)->nullable();
            $table->decimal('customs_value', 14, 2)->nullable();
            $table->decimal('gst_base', 14, 2)->nullable();
            $table->decimal('customs_duty_amount', 14, 2)->nullable();
            $table->decimal('excise_amount', 14, 2)->nullable();
            $table->decimal('freight_insurance_amount', 14, 2)->nullable();
            $table->decimal('import_gst_amount', 14, 2)->nullable();
            $table->string('tariff_source_url', 500)->nullable();
            $table->date('tariff_verified_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropColumn([
                'gst_source', 'customs_assessment_reference', 'import_declaration_number', 'hs_code',
                'verified_duty_rate', 'customs_value', 'gst_base', 'customs_duty_amount', 'excise_amount',
                'freight_insurance_amount', 'import_gst_amount', 'tariff_source_url', 'tariff_verified_on',
            ]);
        });
    }
};
