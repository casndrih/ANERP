<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('credit_note_number', 80);
            $table->date('credit_note_date');
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('PGK');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'credit_note_number'], 'customer_credit_note_number_unique');
            $table->index(['company_id', 'customer_invoice_id', 'credit_note_date'], 'customer_credit_note_invoice_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_notes');
    }
};
