<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('special_bills', 'special_bill_number')) {
            Schema::table('special_bills', function (Blueprint $table) {
                $table->string('special_bill_number')->nullable()->after('id');
                $table->enum('target_type', ['ALL_OCCUPIED', 'SELECTED_HOUSES'])->nullable()->after('due_date');
            });

            DB::table('special_bills')->orderBy('id')->each(function ($bill) {
                DB::table('special_bills')->where('id', $bill->id)->update([
                    'special_bill_number' => 'SPB-'.date('Ymd', strtotime($bill->created_at ?? 'now')).'-'.strtoupper(substr((string) Str::ulid(), -8)),
                    'target_type' => DB::table('special_bill_targets')->where('special_bill_id', $bill->id)->exists()
                        ? 'SELECTED_HOUSES'
                        : 'ALL_OCCUPIED',
                ]);
            });

            DB::statement("ALTER TABLE special_bills MODIFY special_bill_number VARCHAR(255) NOT NULL");
            DB::statement("ALTER TABLE special_bills MODIFY target_type ENUM('ALL_OCCUPIED','SELECTED_HOUSES') NOT NULL");
            DB::statement("UPDATE special_bills SET status='PENDING_APPROVAL' WHERE status='DRAFT'");
            DB::statement("ALTER TABLE special_bills MODIFY status ENUM('PENDING_APPROVAL','APPROVED','CANCELLED') NOT NULL DEFAULT 'PENDING_APPROVAL'");
            Schema::table('special_bills', function (Blueprint $table) {
                $table->unique('special_bill_number');
                $table->dropIndex(['status', 'due_date']);
                $table->index(['status', 'target_type', 'due_date']);
            });
        }

        if (DB::getDriverName() === 'mysql') {
            $this->restrictForeign('special_bill_targets', 'special_bill_id');
            $this->restrictForeign('special_bill_documents', 'special_bill_id');
        }
    }

    private function restrictForeign(string $table, string $column): void
    {
        $foreign = "{$table}_{$column}_foreign";
        DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$foreign}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$foreign} FOREIGN KEY ({$column}) REFERENCES special_bills(id) ON DELETE RESTRICT");
    }

    public function down(): void {}
};
