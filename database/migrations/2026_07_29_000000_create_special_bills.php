<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('special_bills', function (Blueprint $table) {
            $table->id();
            $table->string('special_bill_number')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('amount');
            $table->date('due_date');
            $table->enum('target_type', ['ALL_OCCUPIED', 'SELECTED_HOUSES']);
            $table->enum('status', ['PENDING_APPROVAL', 'APPROVED', 'CANCELLED'])->default('PENDING_APPROVAL');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'target_type', 'due_date']);
        });
        Schema::create('special_bill_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['special_bill_id', 'house_id']);
        });
        Schema::create('special_bill_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('special_bill_id')->constrained()->restrictOnDelete();
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('special_bill_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->string('fee_code')->nullable()->change();
            $table->string('fee_name_snapshot')->nullable()->change();
            $table->unsignedBigInteger('amount_snapshot')->nullable()->change();
            $table->unique(['special_bill_id', 'house_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropUnique(['special_bill_id', 'house_id']);
            $table->dropConstrainedForeignId('special_bill_id');
        });
        Schema::dropIfExists('special_bill_documents');
        Schema::dropIfExists('special_bill_targets');
        Schema::dropIfExists('special_bills');
    }
};
