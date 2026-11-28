<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->decimal('net_amount', 14, 2)->nullable()->after('amount');
            $table->decimal('gst_amount', 14, 2)->default(0)->after('net_amount');
            $table->decimal('gst_rate', 5, 2)->default(0)->after('gst_amount');
            $table->string('gst_treatment', 20)->default('out_of_scope')->after('gst_rate');
        });
        DB::table('customer_credit_notes')->update(['net_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropColumn(['net_amount', 'gst_amount', 'gst_rate', 'gst_treatment']);
        });
    }
};
