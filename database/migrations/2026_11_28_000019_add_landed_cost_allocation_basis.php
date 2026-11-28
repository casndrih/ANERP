<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('landed_cost_allocations', function (Blueprint $table) {
            $table->string('allocation_basis', 24)->default('line_value')->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('landed_cost_allocations', function (Blueprint $table) {
            $table->dropColumn('allocation_basis');
        });
    }
};
