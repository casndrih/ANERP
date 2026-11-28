<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_valuation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('valuation_method', 24)->default('weighted_average');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_cost_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->decimal('inventory_value', 16, 2)->default(0);
            $table->decimal('average_unit_cost', 14, 4)->default(0);
            $table->boolean('is_initialized')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'warehouse_id', 'product_id'], 'inventory_cost_balance_scope_unique');
            $table->index(['company_id', 'product_id']);
        });

        Schema::create('inventory_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->decimal('quantity_received', 14, 3);
            $table->decimal('quantity_remaining', 14, 3);
            $table->decimal('unit_cost', 14, 4);
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['company_id', 'warehouse_id', 'product_id', 'received_at'], 'inventory_cost_layer_fifo_index');
            $table->unique('source_movement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layers');
        Schema::dropIfExists('inventory_cost_balances');
        Schema::dropIfExists('inventory_valuation_settings');
    }
};
