<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('credit_limit', 14, 2)->nullable()->after('billing_address');
            $table->boolean('credit_hold')->default(false)->after('credit_limit');
            $table->string('credit_hold_reason', 255)->nullable()->after('credit_hold');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_limit', 'credit_hold', 'credit_hold_reason']);
        });
    }
};
