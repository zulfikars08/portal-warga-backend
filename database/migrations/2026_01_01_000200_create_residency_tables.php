<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('houses', function(Blueprint $table){$table->id();$table->string('block_code',10);$table->string('house_number',20);$table->string('house_code',40)->unique();$table->timestamps();$table->softDeletes();$table->unique(['block_code','house_number']);});
  Schema::create('residents', function(Blueprint $table){$table->id();$table->string('full_name');$table->string('phone',30)->nullable();$table->string('marital_status',30);$table->boolean('active')->default(true)->index();$table->timestamps();});
  Schema::create('households', function(Blueprint $table){$table->id();$table->foreignId('house_id')->constrained()->restrictOnDelete();$table->foreignId('head_resident_id')->constrained('residents')->restrictOnDelete();$table->enum('occupancy_type',['PERMANENT','CONTRACT']);$table->date('started_at');$table->date('ended_at')->nullable();$table->date('contract_started_at')->nullable();$table->date('contract_ended_at')->nullable();$table->boolean('active')->default(true);$table->timestamps();$table->index(['house_id','active']);$table->index(['head_resident_id','active']);});
  Schema::create('household_members', function(Blueprint $table){$table->id();$table->foreignId('household_id')->constrained()->cascadeOnDelete();$table->foreignId('resident_id')->constrained()->restrictOnDelete();$table->enum('member_role',['HEAD','MEMBER']);$table->date('joined_at');$table->date('left_at')->nullable();$table->boolean('active')->default(true);$table->timestamps();$table->unique(['household_id','resident_id']);$table->index(['household_id','member_role','active']);});
  Schema::create('private_documents', function(Blueprint $table){$table->id();$table->foreignId('resident_id')->constrained()->cascadeOnDelete();$table->enum('document_type',['KTP','KK']);$table->string('storage_path');$table->string('original_name');$table->string('mime_type',100);$table->unsignedBigInteger('size_bytes');$table->json('metadata')->nullable();$table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();$table->timestamps();$table->index(['resident_id','document_type']);});
 }
 public function down(): void {foreach(['private_documents','household_members','households','residents','houses'] as $table)Schema::dropIfExists($table);}
};