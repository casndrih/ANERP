<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_movement_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique('stock_movement_id');
            $table->unique('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_journals');
    }
};
