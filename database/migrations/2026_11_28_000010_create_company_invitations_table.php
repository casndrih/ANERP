<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('company_invitations', function (Blueprint $t) {
   $t->id(); $t->foreignId('company_id')->constrained()->cascadeOnDelete();
   $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
   $t->string('email'); $t->string('role_code',60); $t->char('token_hash',64)->unique();
   $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
   $t->timestamp('expires_at'); $t->timestamp('accepted_at')->nullable(); $t->timestamp('revoked_at')->nullable();
   $t->timestamps(); $t->index(['company_id','email','accepted_at','revoked_at']);
  });
 }
 public function down(): void { Schema::dropIfExists('company_invitations'); }
};
