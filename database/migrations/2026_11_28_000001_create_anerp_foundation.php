<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('legal_name')->nullable();
            $t->string('registration_number')->nullable(); $t->string('tin')->nullable();
            $t->string('currency', 3)->default('PGK'); $t->string('country', 2)->default('PG');
            $t->string('timezone')->default('Pacific/Port_Moresby'); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code'); $t->string('name'); $t->string('province')->nullable();
            $t->string('district')->nullable(); $t->string('address')->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('company_user', function (Blueprint $t) {
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('role_code')->default('viewer'); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->primary(['company_id', 'user_id']);
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code'); $t->string('name'); $t->string('location')->nullable();
            $t->boolean('is_active')->default(true); $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('sku'); $t->string('barcode')->nullable(); $t->string('name');
            $t->text('description')->nullable(); $t->string('unit')->default('each');
            $t->decimal('standard_cost', 14, 2)->default(0); $t->decimal('selling_price', 14, 2)->default(0);
            $t->boolean('track_stock')->default(true); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->unique(['company_id', 'sku']);
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('movement_type'); $t->decimal('quantity', 14, 3);
            $t->decimal('unit_cost', 14, 2)->default(0); $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable(); $t->timestamp('occurred_at');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps(); $t->index(['company_id', 'product_id', 'warehouse_id', 'occurred_at']);
        });
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code'); $t->string('name'); $t->string('tin')->nullable();
            $t->string('email')->nullable(); $t->string('phone')->nullable();
            $t->text('address')->nullable(); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->unique(['company_id', 'code']);
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('employee_number'); $t->string('first_name'); $t->string('last_name');
            $t->string('email')->nullable(); $t->string('phone')->nullable();
            $t->string('employment_type')->nullable(); $t->date('hire_date')->nullable();
            $t->date('termination_date')->nullable(); $t->string('status')->default('active');
            $t->timestamps(); $t->unique(['company_id', 'employee_number']);
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('account_code'); $t->string('name'); $t->string('account_type');
            $t->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->boolean('is_control_account')->default(false); $t->boolean('is_active')->default(true);
            $t->timestamps(); $t->unique(['company_id', 'account_code']);
        });
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('journal_number'); $t->date('journal_date'); $t->string('source')->default('manual');
            $t->string('status')->default('draft'); $t->text('description')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable(); $t->timestamps();
            $t->unique(['company_id', 'journal_number']);
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->text('description')->nullable(); $t->decimal('debit', 14, 2)->default(0);
            $t->decimal('credit', 14, 2)->default(0); $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event'); $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable(); $t->jsonb('before_data')->nullable();
            $t->jsonb('after_data')->nullable(); $t->ipAddress('ip_address')->nullable();
            $t->timestamp('created_at')->useCurrent(); $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events','journal_lines','journal_entries','accounts','employees','suppliers','stock_movements','products','warehouses','company_user','branches','companies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
