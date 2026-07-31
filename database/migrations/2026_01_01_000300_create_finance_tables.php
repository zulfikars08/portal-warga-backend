<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('fee_rates', function(Blueprint $table) {
   $table->id();
   $table->enum('fee_code', ['SECURITY', 'CLEANING']);
   $table->string('name');
   $table->unsignedBigInteger('amount');
   $table->date('effective_from');
   $table->date('effective_until')->nullable();
   $table->boolean('active')->default(true);
   $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamps();
   $table->index(['fee_code', 'active', 'effective_from']);
  });
  Schema::create('bills', function(Blueprint $table) {
   $table->id();
   $table->foreignId('house_id')->constrained()->restrictOnDelete();
   $table->foreignId('household_id')->constrained()->restrictOnDelete();
   $table->foreignId('fee_rate_id')->nullable()->constrained()->restrictOnDelete();
   $table->enum('fee_code', ['SECURITY', 'CLEANING']);
   $table->foreignId('responsible_head_resident_id')->constrained('residents')->restrictOnDelete();
   $table->string('house_code_snapshot');
   $table->string('responsible_head_name_snapshot');
   $table->string('fee_name_snapshot');
   $table->unsignedBigInteger('amount_snapshot');
   $table->string('type');
   $table->string('title');
   $table->date('period');
   $table->date('due_date');
   $table->unsignedBigInteger('amount');
   $table->unsignedBigInteger('paid_amount')->default(0);
   $table->string('status')->default('UNPAID');
   $table->json('fee_snapshot')->nullable();
   $table->text('note')->nullable();
   $table->timestamps();
   $table->unique(['house_id', 'fee_code', 'period']);
   $table->index(['household_id', 'period']);
  });
  Schema::create('payments', function(Blueprint $table) {
   $table->id();
   $table->string('transaction_number')->unique();
   $table->foreignId('house_id')->constrained()->restrictOnDelete();
   $table->foreignId('household_id')->constrained()->restrictOnDelete();
   $table->foreignId('payer_resident_id')->constrained('residents')->restrictOnDelete();
   $table->enum('payment_method', ['CASH', 'TRANSFER']);
   $table->unsignedBigInteger('amount');
   $table->dateTime('paid_at');
   $table->enum('status', ['POSTED', 'CANCELLED'])->default('POSTED');
   $table->text('note')->nullable();
   $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
   $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
   $table->dateTime('cancelled_at')->nullable();
   $table->text('cancel_reason')->nullable();
   $table->foreignId('replaces_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
   $table->timestamps();
   $table->unique('replaces_payment_id');
   $table->index(['status', 'paid_at']);
  });
  Schema::create('payment_allocations', function(Blueprint $table) {
   $table->id();
   $table->foreignId('payment_id')->constrained()->restrictOnDelete();
   $table->foreignId('bill_id')->constrained()->restrictOnDelete();
   $table->unsignedBigInteger('amount');
   $table->timestamps();
   $table->unique(['payment_id','bill_id']);
   $table->index('bill_id');
  });
  Schema::create('payment_proofs', function(Blueprint $table) {
   $table->id();
   $table->foreignId('payment_id')->constrained()->restrictOnDelete();
   $table->string('storage_path');
   $table->string('original_name');
   $table->string('mime_type',100);
   $table->unsignedBigInteger('size_bytes');
   $table->unsignedBigInteger('transfer_amount')->nullable();
   $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
   $table->json('metadata')->nullable();
   $table->timestamps();
  });
  Schema::create('expense_categories', function(Blueprint $table) {
   $table->id();
   $table->string('name')->unique();
   $table->boolean('active')->default(true);
   $table->timestamps();
  });
  Schema::create('expenses', function(Blueprint $table) {
   $table->id();
   $table->string('transaction_number')->unique();
   $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
   $table->string('title');
   $table->text('description')->nullable();
   $table->unsignedBigInteger('amount');
   $table->date('spent_at');
   $table->enum('status', ['POSTED','CANCELLED'])->default('POSTED');
   $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
   $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
   $table->dateTime('cancelled_at')->nullable();
   $table->text('cancel_reason')->nullable();
   $table->foreignId('replaces_expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
   $table->timestamps();
   $table->unique('replaces_expense_id');
   $table->index(['status','spent_at']);
   $table->index(['expense_category_id','spent_at']);
  });
  Schema::create('expense_proofs', function(Blueprint $table) {
   $table->id();
   $table->foreignId('expense_id')->constrained()->restrictOnDelete();
   $table->string('storage_path');
   $table->string('original_name');
   $table->string('mime_type',100);
   $table->unsignedBigInteger('size_bytes');
   $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
   $table->json('metadata')->nullable();
   $table->timestamps();
  });
  Schema::create('opening_balances',function(Blueprint $t){$t->id();$t->date('as_of')->unique();$t->bigInteger('amount');$t->text('note')->nullable();$t->timestamps();});
  Schema::create('audit_logs',function(Blueprint $t){$t->id();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('action');$t->string('auditable_type');$t->unsignedBigInteger('auditable_id')->nullable();$t->json('old_values')->nullable();$t->json('new_values')->nullable();$t->string('ip',45)->nullable();$t->timestamps();$t->index(['auditable_type','auditable_id']);});
 }
 public function down(): void {foreach(['audit_logs','opening_balances','expense_proofs','expenses','expense_categories','payment_proofs','payment_allocations','payments','bills','fee_rates'] as $table)Schema::dropIfExists($table);}
};