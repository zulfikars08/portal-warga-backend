<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('residents', fn (Blueprint $table) => $table->string('nik', 32)->nullable()->index()->after('full_name'));
    }

    public function down(): void
    {
        Schema::table('residents', fn (Blueprint $table) => $table->dropColumn('nik'));
    }
};
