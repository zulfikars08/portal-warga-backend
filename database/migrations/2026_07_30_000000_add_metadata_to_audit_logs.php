<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('audit_logs',fn(Blueprint $table)=>$table->json('metadata')->nullable()->after('new_values'));}
    public function down():void{Schema::table('audit_logs',fn(Blueprint $table)=>$table->dropColumn('metadata'));}
};